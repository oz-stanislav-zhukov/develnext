<?php
namespace ide\build;

use ide\forms\BuildProgressForm;
use ide\forms\BuildSuccessForm;
use ide\Ide;
use ide\Logger;
use ide\project\behaviours\BundleProjectBehaviour;
use ide\project\behaviours\PhpProjectBehaviour;
use ide\project\behaviours\RunBuildProjectBehaviour;
use ide\project\Project;
use ide\project\ProjectFile;
use ide\systems\ProjectSystem;
use ide\utils\FileUtils;
use php\compress\ZipFile;
use php\gui\UXDialog;
use php\io\File;
use php\io\IOException;
use php\io\Stream;
use php\lang\Process;
use php\lib\arr;
use php\lib\fs;
use php\lib\str;
use php\util\Regex;

class AntOneJarBuildType extends AbstractBuildType
{
    const JAVAFX_MODULES = 'javafx.base,javafx.graphics,javafx.controls,javafx.fxml,javafx.media,javafx.web,javafx.swing';
    const NATIVE_ACCESS_MODULES = 'ALL-UNNAMED,javafx.graphics,javafx.media,javafx.web';

    /**
     * @var string
     */
    private $mainClass;

    /** @var string|null */
    private $targetPlatform;

    /**
     * @return string
     */
    public function getMainClass(): string
    {
        return $this->mainClass;
    }

    /**
     * @param string $mainClass
     */
    public function setMainClass(string $mainClass)
    {
        $this->mainClass = $mainClass;
    }

    public function setTargetPlatform($targetPlatform)
    {
        $this->targetPlatform = $targetPlatform;
    }

    /**
     * @return string
     */
    function getName()
    {
        return "JAR Application";
    }

    /**
     * @return string
     */
    function getDescription()
    {
        return 'Портативное Java-приложение со встроенным runtime';
    }

    public function getConfigForm()
    {
        return 'blocks/_PortableApplicationConfig.fxml';
    }

    public function getDefaultConfig()
    {
        $platformNames = ['win' => 'Windows', 'linux' => 'Linux', 'mac' => 'macOS'];

        return [
            'platform' => $platformNames[Ide::get()->getPlatform()],
            'portableFolder' => true,
        ];
    }

    /**
     * @return mixed
     */
    function getIcon()
    {
        return 'icons/jarFile32.png';
    }

    /**
     * @param Project $project
     *
     * @return string
     */
    function getBuildPath(Project $project)
    {
        return $project->getRootDir() . '/build/dist';
    }

    public function getConfig()
    {
        $config = parent::getConfig();

        foreach ($this->getDefaultConfig() as $name => $value) {
            if (!isset($config[$name])) {
                $config[$name] = $value;
            }
        }

        if ($this->targetPlatform) {
            $platformNames = ['win' => 'Windows', 'linux' => 'Linux', 'mac' => 'macOS'];
            $config['platform'] = $platformNames[$this->targetPlatform];
        }

        $config['mainClass'] = $this->getMainClass();

        return $config;
    }

    public static function getTargetPlatform(array $config)
    {
        $platform = isset($config['platform']) ? str::lower($config['platform']) : null;

        if ($platform === 'windows' || $platform === 'win') return 'win';
        if ($platform === 'macos' || $platform === 'mac') return 'mac';
        if ($platform === 'linux') return 'linux';

        // Compatibility with settings written by pre-unified builds.
        if (!empty($config['windows'])) return 'win';
        if (!empty($config['mac'])) return 'mac';
        if (!empty($config['linux'])) return 'linux';

        return Ide::get()->getPlatform();
    }

    public static function makeAntBuildFile(Project $project, array $config)
    {
        $platform = static::getTargetPlatform($config);
        $javaRuntimePath = isset($config['javaRuntimePath'])
            ? $config['javaRuntimePath']
            : Ide::get()->getPortableJavaRuntimePath($platform);
        $javafxRuntimePath = isset($config['javafxRuntimePath'])
            ? $config['javafxRuntimePath']
            : Ide::get()->getPortableJavaFxPath($platform);

        $project->copyModuleFiles($project->getRootDir() . "/build/dist/lib");
        FileUtils::copyDirectory($javafxRuntimePath, $project->getRootDir() . '/build/dist/lib/javafx');
        FileUtils::copyFile(Ide::getOwnFile('javafx-compatibility.args'), $project->getRootDir() . '/build/dist/javafx-compatibility.args');

        $content = FileUtils::get('res://ide/build/ant/buildDist.xml');
        $content = str::replace($content, '#NAME#', $project->getName());
        $content = str::replace($content, '#JAVA_RUNTIME_DIR#', $javaRuntimePath);
        $content = str::replace($content, '#JRE_DIR#', $javaRuntimePath);
        $content = str::replace($content, '#BASE_DIR#', $project->getRootDir());

        $jarContent = '';

        $classPaths = [$project->getSrcGeneratedDirectory(), $project->getSrcDirectory()];

        if ($bundleBehaviour = BundleProjectBehaviour::get()) {
            $classPaths = $bundleBehaviour->getSourceDirectories();
        }

        foreach ($classPaths as $src) {
            $excludes = ".debug/** **/*.source **/*.sourcemap **/*.axml";

            if ($php = PhpProjectBehaviour::get()) {
                if ($php->isByteCodeEnabled()) {
                    $excludes .= " **/*.php";
                }
            }

            $jarContent .= "\t<fileset dir='$src' excludes='$excludes' erroronmissingdir='false'/>\n";
        }

        $content = str::replace($content, '#JAR_CONTENT#', $jarContent);
        $content = str::replace($content, '#DIST_CONTENT#', '');
        $content = str::replace($content, '#LAUNCH4J_DIR#', Ide::get()->getLaunch4JPath());

        $content = str::replace($content, '#MAIN_CLASS#', $config['mainClass']);

        if (!empty($config['portableJar']) && $platform === 'win') {
            $content = str::replace($content, '#L4J_JAR_FILE#', '');
            $content = str::replace($content, '#L4J_DONT_WRAP_JAR#', 'true');
            $content = str::replace($content, '#L4J_CLASS_PATH#', $project->getName() . '.jar');
            $content = str::replace($content, '#L4J_DELETE_JAR#', '');
        } elseif ($config['oneJar']) {
            $content = str::replace($content, '#L4J_JAR_FILE#', '${dist}/' . $project->getName() . '.jar');
            $content = str::replace($content, '#L4J_DONT_WRAP_JAR#', 'false');
            $content = str::replace($content, '#L4J_CLASS_PATH#', 'lib/*');
            $content = str::replace($content, '#L4J_DELETE_JAR#', '<delete file="${dist}/' . $project->getName() . '.jar" failonerror="false" />');
        } else {
            $content = str::replace($content, '#L4J_JAR_FILE#', '');
            $content = str::replace($content, '#L4J_DONT_WRAP_JAR#', 'true');
            $content = str::replace($content, '#L4J_CLASS_PATH#', 'lib/*');
            $content = str::replace($content, '#L4J_DELETE_JAR#', '');
        }

        $content = str::replace($content, '#L4J_RUNTIME_PATH#', 'runtime');
        $content = str::replace($content, '#L4J_JRE_PATH#', 'runtime');

        if (!empty($config['exeIcoPath'])) {
            $icoFile = File::of(Ide::get()->getOpenedProject()->getRootDir() . "/" . $config['exeIcoPath']);

            if (!$icoFile->isFile()) {
                $icoFile = File::of($config['exeIcoPath']);
            }

            if ($icoFile->isFile()) {
                // fix windres.exe bug.
                $tmpIconFile = File::createTemp(str::uuid(), '.ico');
                $tmpIconFile->deleteOnExit();

                fs::copy($icoFile, $tmpIconFile);

                $content = str::replace($content, '#L4J_ICON_FILE#', $tmpIconFile);
            } else {
                $content = str::replace($content, 'icon="#L4J_ICON_FILE#"', '');
            }
        } else {
            $content = str::replace($content, 'icon="#L4J_ICON_FILE#"', '');
        }

        if (empty($config['l4j'])) {
            $content = Regex::of('\\<launch4j\\>.*\\<\\/launch4j\\>', 's')->with($content)->replaceGroup(0, '');
        }


        $serviceFiles = [];
        $oneJarContent = [];

        $addedModuleNames = [];

        foreach ($project->getModules() as $module) {
            if ($module->isProvided()) continue;

            if ($module->getType() == 'jarfile') {
                $name = fs::name($module->getId());

                if ($addedModuleNames[$name]) {
                    continue;
                }

                $addedModuleNames[$name] = 1;

                if ($php = PhpProjectBehaviour::get()) {
                    $excl = $php->isByteCodeEnabled() ? '**/*.php' : '';
                } else {
                    $excl = '';
                }

                $oneJarContent[] = "<zipfileset src='\${dist}/lib/{$name}' excludes='JPHP-INF/sdk/** META-INF/services/** module-info.class META-INF/versions/**/module-info.class $excl' />";

                try {
                    $zipFile = new ZipFile($module->getId());

                    foreach ($zipFile->statAll() as $stat) {
                        $serviceName = $stat['name'];

                        if (str::startsWith($serviceName, 'META-INF/services/') && !str::endsWith($serviceName, '/')) {
                            $zipFile->read($serviceName, function ($stat, Stream $stream) use (&$serviceFiles, $serviceName) {
                                foreach (str::split("$stream", "\n") as $provider) {
                                    $provider = str::trim($provider);

                                    if ($provider && !str::startsWith($provider, '#')) {
                                        $serviceFiles[$serviceName][$provider] = $provider;
                                    }
                                }
                            });
                        }
                    }
                } catch (IOException $e) {
                    Logger::warn("Unable to read zip data from {$module->getId()}, {$e->getMessage()}");
                }
            }
        }

        $content = str::replace($content, '#ONE_JAR_CONTENT#', str::join($oneJarContent, " "));

        FileUtils::put($project->getRootDir() . "/build.xml", $content);

        foreach ($serviceFiles as $serviceName => $providers) {
            FileUtils::put($project->getRootDir() . "/build/dist/gen/$serviceName", str::join($providers, "\n") . "\n");
        }
    }

    protected static function movePortablePath($source, $destination)
    {
        $source = File::of($source);
        $destination = File::of($destination);
        $destination->getParentFile()->mkdirs();

        if (!$source->renameTo($destination)) {
            if ($source->isDirectory()) {
                FileUtils::copyDirectory($source, $destination);
                FileUtils::deleteDirectory($source);
            } else {
                FileUtils::copyFile($source, $destination);
                $source->delete();
            }
        }
    }

    public static function finalizePortableLayout(Project $project, array $config)
    {
        $platform = static::getTargetPlatform($config);
        $name = $project->getName();
        $dist = $project->getRootDir() . '/build/dist';
        $modules = static::JAVAFX_MODULES;
        $nativeAccess = static::NATIVE_ACCESS_MODULES;

        if ($platform === 'win') {
            $script = "@echo off\r\nsetlocal\r\nset \"APP_HOME=%~dp0\"\r\n"
                . "\"%APP_HOME%runtime\\bin\\javaw.exe\" --module-path \"%APP_HOME%lib\\javafx\" "
                . "--add-modules $modules --enable-native-access=$nativeAccess \"@%APP_HOME%javafx-compatibility.args\" -jar \"%APP_HOME%$name.jar\" %*\r\n";
            FileUtils::put("$dist/run.bat", $script);

            return;
        }

        if ($platform === 'linux') {
            $launcher = str::join([
                '#!/usr/bin/env sh',
                'set -eu',
                'APP_HOME="$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)"',
                'exec "$APP_HOME/runtime/bin/java" --module-path "$APP_HOME/lib/javafx" \\',
                "  --add-modules $modules \\",
                "  --enable-native-access=$nativeAccess \\",
                '  "@$APP_HOME/javafx-compatibility.args" \',
                "  -jar \"\$APP_HOME/$name.jar\" \"\$@\"",
                '',
            ], "\n");
            $launcherFile = File::of("$dist/$name");
            FileUtils::put($launcherFile, $launcher);
            $launcherFile->setExecutable(true, false);

            $desktop = str::join([
                '[Desktop Entry]',
                'Type=Application',
                "Name=$name",
                "Exec=sh -c 'cd \"\$(dirname \"\$1\")\" && exec \"./$name\"' sh %k",
                'Icon=application-x-executable',
                'Terminal=false',
                'Categories=Development;',
                '',
            ], "\n");
            FileUtils::put("$dist/$name.desktop", $desktop);

            return;
        }

        $contents = "$dist/$name.app/Contents";
        static::movePortablePath("$dist/$name.jar", "$contents/app/$name.jar");
        static::movePortablePath("$dist/lib", "$contents/lib");
        static::movePortablePath("$dist/runtime", "$contents/runtime");
        static::movePortablePath("$dist/javafx-compatibility.args", "$contents/javafx-compatibility.args");
        File::of("$contents/Resources")->mkdirs();
        File::of("$contents/MacOS")->mkdirs();
        FileUtils::put("$contents/Resources/icon.icns", Stream::getContents('res://.data/img/DevelNextIco.icns'));

        $launcher = str::join([
            '#!/bin/sh',
            'set -eu',
            'CONTENTS="$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)"',
            'exec "$CONTENTS/runtime/bin/java" --module-path "$CONTENTS/lib/javafx" \\',
            "  --add-modules $modules \\",
            "  --enable-native-access=$nativeAccess \\",
            '  "@$CONTENTS/javafx-compatibility.args" \',
            "  -jar \"\$CONTENTS/app/$name.jar\" \"\$@\"",
            '',
        ], "\n");
        $launcherFile = File::of("$contents/MacOS/$name");
        FileUtils::put($launcherFile, $launcher);
        $launcherFile->setExecutable(true, false);

        $bundleName = str::replace(str::replace($name, '&', '&amp;'), '<', '&lt;');
        $bundleId = Regex::of('[^a-z0-9.-]+')->with(str::lower($name))->replaceGroup(0, '-');
        $plist = str::join([
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">',
            '<plist version="1.0">',
            '<dict>',
            '  <key>CFBundleName</key>', "  <string>$bundleName</string>",
            '  <key>CFBundleDisplayName</key>', "  <string>$bundleName</string>",
            '  <key>CFBundleExecutable</key>', "  <string>$bundleName</string>",
            '  <key>CFBundleIdentifier</key>', "  <string>org.develnext.app.$bundleId</string>",
            '  <key>CFBundleVersion</key>', '  <string>1.0</string>',
            '  <key>CFBundleShortVersionString</key>', '  <string>1.0</string>',
            '  <key>CFBundleIconFile</key>', '  <string>icon.icns</string>',
            '  <key>LSMinimumSystemVersion</key>', '  <string>12.0</string>',
            '  <key>NSHighResolutionCapable</key>', '  <true/>',
            '</dict>',
            '</plist>',
            '',
        ], "\n");
        FileUtils::put("$contents/Info.plist", $plist);
    }

    /**
     * @param Project $project
     *
     * @param bool $finished
     *
     * @return mixed
     */
    function onExecute(Project $project, $finished = true)
    {
        $config = $this->getConfig();
        $platform = static::getTargetPlatform($config);
        $config['oneJar'] = true;
        $config['portableJar'] = true;
        $config['l4j'] = $platform === 'win';
        $config['javaRuntimePath'] = Ide::get()->getPortableJavaRuntimePath($platform);
        $config['javafxRuntimePath'] = Ide::get()->getPortableJavaFxPath($platform);

        if (!$config['javaRuntimePath']) {
            UXDialog::showAndWait("Невозможно собрать приложение: runtime JDK 25 для платформы '$platform' не найден в tools/runtime/$platform.", 'ERROR');
            return false;
        }

        if (!$config['javafxRuntimePath']) {
            UXDialog::showAndWait("Невозможно собрать приложение: JavaFX для платформы '$platform' не найден в tools/javafx/$platform.", 'ERROR');
            return false;
        }

        if ($platform === 'win' && (!Ide::get()->getLaunch4JProgram() || !Ide::get()->getLaunch4JPath())) {
            UXDialog::showAndWait('Невозможно собрать приложение: не найден Launch4j.', 'ERROR');
            return false;
        }

        FileUtils::deleteDirectory($this->getBuildPath($project));
        $dialog = new BuildProgressForm();
        $dialog->show();

        $onExitProcess = function ($exitValue) use ($project, $dialog, $finished, $config, $platform) {
            Logger::info("Finish executing: exitValue = $exitValue");

            if ($exitValue == 0) {
                AntOneJarBuildType::finalizePortableLayout($project, $config);

                if ($finished) {
                    if (is_callable($finished)) {
                        $finished();

                        return;
                    }

                    $dialog = new BuildSuccessForm();
                    $dialog->setBuildPath($this->getBuildPath($project));
                    $dialog->setOpenDirectory($this->getBuildPath($project));

                    if ($platform === 'win') {
                        $pathToProgram = "{$this->getBuildPath($project)}/{$project->getName()}.exe";
                    } elseif ($platform === 'linux') {
                        $pathToProgram = "{$this->getBuildPath($project)}/{$project->getName()}";
                    } else {
                        $pathToProgram = "{$this->getBuildPath($project)}/{$project->getName()}.app/Contents/MacOS/{$project->getName()}";
                    }

                    $dialog->setRunProgram($pathToProgram);

                    $dialog->showAndWait();
                }
            }
        };
        $dialog->setOnExitProcess($onExitProcess);

        ProjectSystem::saveOnlyRequired();
        $targets = [$platform === 'win' ? 'distAppWindows' : ($platform === 'mac' ? 'distAppMac' : 'distAppLinux')];

        ProjectSystem::compileAll(Project::ENV_PROD, $dialog, 'ant ' . str::join($targets, ' '), function ($success) use ($project, $dialog, $config, $targets) {
            if ($success) {
                $this->makeAntBuildFile($project, $config);

                $args = [Ide::get()->getApacheAntProgram()];

                foreach ($targets as $target) {
                    $args[] = $target;
                }

                $process = new Process($args, $project->getRootDir(), Ide::get()->makeEnvironment());

                $process = $process->start();

                $dialog->watchProcess($process);
            } else {
                $dialog->stopWithError();
            }
        });
    }
}
