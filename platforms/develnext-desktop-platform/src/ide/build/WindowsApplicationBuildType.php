<?php
namespace ide\build;

use ide\commands\BuildProjectCommand;
use ide\formats\templates\Launch4jConfigTemplate;
use ide\forms\BuildProgressForm;
use ide\forms\BuildProjectForm;
use ide\forms\BuildSuccessForm;
use ide\Ide;
use ide\Logger;
use ide\project\behaviours\GradleProjectBehaviour;
use ide\project\behaviours\GuiFrameworkProjectBehaviour;
use ide\project\Project;
use ide\systems\ProjectSystem;
use ide\utils\FileUtils;
use php\gui\event\UXEvent;
use php\gui\UXAlert;
use php\gui\UXDialog;
use php\gui\UXFileChooser;
use php\gui\UXImage;
use php\gui\UXImageView;
use php\gui\UXLabel;
use php\gui\UXTextArea;
use php\gui\UXTextField;
use php\io\File;
use php\io\FileStream;
use php\io\Stream;
use php\lang\Process;
use php\lib\arr;
use php\lib\fs;
use php\lib\Items;
use php\lib\Str;
use php\xml\XmlProcessor;

/**
 * Class WindowsApplicationBuildType
 * @package ide\build
 */
class WindowsApplicationBuildType extends AbstractBuildType
{
    /**
     * @return string
     */
    function getName()
    {
        return 'Windows Application';
    }

    /**
     * @return string
     */
    function getDescription()
    {
        return 'Программа для Windows в виде исполняемого файла';
    }

    /**
     * @return mixed
     */
    function getIcon()
    {
        return 'icons/windowsOS32.png';
    }

    public function getConfigForm()
    {
        return 'blocks/_WindowsApplicationConfig.fxml';
    }

    public function getLaunch4jConfigPath(Project $project)
    {
        return File::of($this->getBuildPath($project) . '/launch4j.xml')->getCanonicalFile();
    }

    public function getDefaultConfig()
    {
        return [
            'runtime' => true,
            'jre'    => true,
            'oneJar' => false,
        ];
    }

    public function getConfig()
    {
        $config = parent::getConfig();
        $config['runtime'] = true;
        $config['jre'] = true;

        return $config;
    }

    /**
     * @event openButton.action
     *
     * @param UXEvent $event
     */
    public function doOpenButtonClick(UXEvent $event)
    {
        $dialog = new UXFileChooser();
        $dialog->extensionFilters = [
            ['description' => 'Иконки (.ico)', 'extensions' => ['*.ico']]
        ];

        if ($file = $dialog->execute()) {
            /** @var UXTextField $icon */
            $icon = $event->target->scene->window->{'o_exeIcoPath'};

            $localFile = Ide::get()->getOpenedProject()->getIdeDir() . "/" . Str::replace(__CLASS__, '\\', '.') . ".ico";
            FileUtils::copyFile($file, $localFile);

            $icon->text = FileUtils::relativePath(Ide::get()->getOpenedProject()->getRootDir(), $localFile);
        }
    }

    protected function copyJavaRuntime($project, $cut = true)
    {
        $javaRuntimeHome = Ide::get()->getJavaRuntimePath();

        $newJavaRuntimeHome = $this->getBuildPath($project) . '/runtime';
        FileUtils::copyDirectory($javaRuntimeHome, $newJavaRuntimeHome);

        if ($cut) {
            fs::clean("$newJavaRuntimeHome/bin/server");
        }
    }

    /**
     * @deprecated Use copyJavaRuntime().
     */
    protected function copyJre($project, $cut = true)
    {
        $this->copyJavaRuntime($project, $cut);
    }

    /**
     * @param Project $project
     *
     * @return bool|Process
     */
    function makeExecutableFile(Project $project)
    {
        $config = $this->getConfig();

        $javaRuntimeHome = Ide::get()->getJavaRuntimePath();
        $launch4j = Ide::get()->getLaunch4JProgram();
        $launch4jPath = Ide::get()->getLaunch4JPath();

        if (!$javaRuntimeHome) {
            UXDialog::showAndWait('Невозможно собрать приложение: не найден встроенный Java Runtime.', 'ERROR');
            return false;
        }

        if (!$launch4j) {
            UXDialog::showAndWait('Невозможно собрать приложение: не найден Launch4j.', 'ERROR');
            return false;
        }

        if (!$launch4jPath) {
            UXDialog::showAndWait('Невозможно собрать приложение: не определён путь к Launch4j.', 'ERROR');
            return false;
        }

        $alert = new UXAlert('INFORMATION');
        $alert->contentText = 'Копируем Java VM, это может занять некоторое время ...';
        $alert->show();
        $this->copyJavaRuntime($project);
        $alert->hide();

        $template = new Launch4jConfigTemplate();
        $template->setExeName($project->getName() . '.exe');

        $template->setJavaRuntimePath('runtime');

        $icoFile = File::of($config['exeIcoPath']);

        if ($icoFile->isFile()) {
            $template->setIcoFile($icoFile);
        }

        $icoFile = File::of(Ide::get()->getOpenedProject()->getRootDir() . "/" . $icoFile);

        if ($icoFile->isFile()) {
            $template->setIcoFile($icoFile);
        }

        if ($config['oneJar']) {
            foreach (File::of($this->getBuildPath($project) . '/lib')->findFiles() as $file) {
                if (Str::startsWith($file->getName(), "dn-compile")) {
                    $template->setJarFile('lib/' . $file->getName());
                }
            }
        }

        $configPath = $this->getLaunch4jConfigPath($project);

        $st = new FileStream($configPath, 'w+');
        $template->apply(null, $st);
        $st->close();

        $process = new Process([
            $launch4j, '-Djava.awt.headless=true', '-cp', "$launch4jPath/launch4j-purejava-patch.jar" . File::PATH_SEPARATOR
                . "$launch4jPath/launch4j.jar" . File::PATH_SEPARATOR . "$launch4jPath/lib/*",
            'net.sf.launch4j.Main', $configPath
        ], $launch4jPath, Ide::get()->makeEnvironment());

        return $process->start();
    }

    /**
     * @param Project $project
     *
     * @param bool $libs
     * @return string
     */
    function getBuildPath(Project $project, $libs = false)
    {
        if ($libs) {
            return $project->getRootDir() . '/build/dist/';
        } else {
            return $project->getRootDir() . '/build/dist/';
        }
    }

    /**
     * @param Project $project
     *
     * @param bool $finished
     *
     * @return mixed
     * @throws \Exception
     */
    function onExecute(Project $project, $finished = true)
    {
        FileUtils::deleteDirectory($this->getBuildPath($project));

        $config = $this->getConfig();

        $config['l4j'] = true;

        $dialog = new BuildProgressForm();
        $dialog->show();

        $javaRuntimeHome = Ide::get()->getJavaRuntimePath();
        $launch4j = Ide::get()->getLaunch4JProgram();
        $launch4jPath = Ide::get()->getLaunch4JPath();

        if (!$javaRuntimeHome) {
            UXDialog::showAndWait('Невозможно собрать приложение: не найден встроенный Java Runtime.', 'ERROR');
            return false;
        }

        if (!$launch4j) {
            UXDialog::showAndWait('Невозможно собрать приложение: не найден Launch4j.', 'ERROR');
            return false;
        }

        if (!$launch4jPath) {
            UXDialog::showAndWait('Невозможно собрать приложение: не определён путь к Launch4j.', 'ERROR');
            return false;
        }

        $onExitProcess = function ($exitValue) use ($project, $dialog, $finished) {
            Logger::info("Finish executing: exitValue = $exitValue");

            if ($exitValue == 0) {
                if ($finished) {
                    if (is_callable($finished)) {
                        $finished();

                        return;
                    }

                    $dialog = new BuildSuccessForm();
                    $dialog->setBuildPath($this->getBuildPath($project));
                    $dialog->setOpenDirectory($this->getBuildPath($project));
                    $dialog->setRunProgram("{$this->getBuildPath($project)}/{$project->getName()}.exe");

                    $dialog->showAndWait();
                }
            }
        };
        $dialog->setOnExitProcess($onExitProcess);

        ProjectSystem::saveOnlyRequired();
        ProjectSystem::compileAll(Project::ENV_PROD, $dialog, 'ant jar launch4j', function ($success) use ($project, $dialog, $config) {
            if ($success) {
                AntOneJarBuildType::makeAntBuildFile($project, $config);

                $args = [Ide::get()->getApacheAntProgram(), $config['oneJar'] ? 'onejar' : 'jar', 'copy-runtime', 'launch4j'];

                $process = new Process($args, $project->getRootDir(), Ide::get()->makeEnvironment());

                $process = $process->start();

                $dialog->watchProcess($process);
            } else {
                $dialog->stopWithError();
            }
        });
    }
}