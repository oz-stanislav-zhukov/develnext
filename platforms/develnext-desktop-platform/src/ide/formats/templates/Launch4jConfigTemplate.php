<?php
namespace ide\formats\templates;

use ide\formats\AbstractFileTemplate;

class Launch4jConfigTemplate extends AbstractFileTemplate
{
    /**
     * @var string
     */
    protected $javaRuntimePath;

    /**
     * @var string
     */
    protected $exeName;

    /**
     * @var string
     */
    protected $icoFile;

    /**
     * @var string
     */
    protected $jarFile;

    /**
     * @return array
     */
    public function getArguments()
    {
        return [
            'JAVA_RUNTIME_PATH' => $this->javaRuntimePath,
            'JRE_PATH'      => $this->javaRuntimePath,
            'EXE_NAME'      => $this->exeName,
            'ICO_FILE'      => $this->icoFile,
            'JAR_FILE'      => $this->jarFile,
            'DONT_WRAP_JAR' => $this->jarFile ? 'false' : 'true',
        ];
    }

    /**
     * @return string
     */
    public function getJavaRuntimePath()
    {
        return $this->javaRuntimePath;
    }

    /**
     * @param string $javaRuntimePath
     */
    public function setJavaRuntimePath($javaRuntimePath)
    {
        $this->javaRuntimePath = $javaRuntimePath;
    }

    /**
     * @deprecated Use getJavaRuntimePath().
     */
    public function getJrePath()
    {
        return $this->getJavaRuntimePath();
    }

    /**
     * @deprecated Use setJavaRuntimePath().
     * @param string $jrePath
     */
    public function setJrePath($jrePath)
    {
        $this->setJavaRuntimePath($jrePath);
    }

    /**
     * @param string $jarFile
     */
    public function setJarFile($jarFile)
    {
        $this->jarFile = $jarFile;
    }

    /**
     * @return string
     */
    public function getExeName()
    {
        return $this->exeName;
    }

    /**
     * @param string $exeName
     */
    public function setExeName($exeName)
    {
        $this->exeName = $exeName;
    }

    /**
     * @return string
     */
    public function getIcoFile()
    {
        return $this->icoFile;
    }

    /**
     * @param string $icoFile
     */
    public function setIcoFile($icoFile)
    {
        $this->icoFile = $icoFile;
    }
}