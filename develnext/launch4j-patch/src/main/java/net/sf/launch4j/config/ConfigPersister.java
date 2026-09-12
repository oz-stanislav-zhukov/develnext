/*
    Launch4j (http://launch4j.sourceforge.net/)
    Cross-platform Java application wrapper for creating Windows native executables.

    Copyright (c) 2004, 2015 Grzegorz Kowal
    All rights reserved.

    Redistribution and use in source and binary forms, with or without modification,
    are permitted provided that the following conditions are met:

    1. Redistributions of source code must retain the above copyright notice,
       this list of conditions and the following disclaimer.

    2. Redistributions in binary form must reproduce the above copyright notice,
       this list of conditions and the following disclaimer in the documentation
       and/or other materials provided with the distribution.

    3. Neither the name of the copyright holder nor the names of its contributors
       may be used to endorse or promote products derived from this software without
       specific prior written permission.

    THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS"
    AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO,
    THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE
    ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE
    FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES
    (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES;
    LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED
    AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY,
    OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
    OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
*/
package net.sf.launch4j.config;

import com.thoughtworks.xstream.XStream;
import com.thoughtworks.xstream.converters.reflection.PureJavaReflectionProvider;
import com.thoughtworks.xstream.io.xml.DomDriver;
import com.thoughtworks.xstream.security.NoTypePermission;
import com.thoughtworks.xstream.security.NullPermission;
import com.thoughtworks.xstream.security.PrimitiveTypePermission;
import net.sf.launch4j.binding.Validator;
import org.w3c.dom.Document;
import org.w3c.dom.ls.DOMImplementationLS;
import org.w3c.dom.ls.LSSerializer;

import javax.xml.parsers.DocumentBuilder;
import javax.xml.parsers.DocumentBuilderFactory;
import java.io.BufferedWriter;
import java.io.File;
import java.io.FileOutputStream;
import java.io.IOException;
import java.io.OutputStreamWriter;
import java.util.Collection;

/**
 * Launch4j 3.50 ConfigPersister with XStream's pure-Java reflection provider.
 * This avoids initializing SunUnsafeReflectionProvider on modern JDKs.
 */
public class ConfigPersister {
    private static final ConfigPersister INSTANCE = new ConfigPersister();

    private final XStream xstream;
    private Config config;
    private File configPath;

    private ConfigPersister() {
        xstream = new XStream(new PureJavaReflectionProvider(), new DomDriver());

        xstream.addPermission(NoTypePermission.NONE);
        xstream.addPermission(NullPermission.NULL);
        xstream.addPermission(PrimitiveTypePermission.PRIMITIVES);
        xstream.allowTypeHierarchy(Collection.class);
        xstream.allowTypesByWildcard(new String[]{"net.sf.launch4j.config.*"});

        xstream.alias("launch4jConfig", Config.class);
        xstream.alias("classPath", ClassPath.class);
        xstream.alias("jre", Jre.class);
        xstream.alias("splash", Splash.class);
        xstream.alias("versionInfo", VersionInfo.class);

        xstream.addImplicitCollection(Config.class, "headerObjects", "obj", String.class);
        xstream.addImplicitCollection(Config.class, "libs", "lib", String.class);
        xstream.addImplicitCollection(Config.class, "variables", "var", String.class);
        xstream.addImplicitCollection(ClassPath.class, "paths", "cp", String.class);
        xstream.addImplicitCollection(Jre.class, "options", "opt", String.class);
    }

    public static ConfigPersister getInstance() {
        return INSTANCE;
    }

    public Config getConfig() {
        return config;
    }

    public File getConfigPath() {
        return configPath;
    }

    public File getOutputPath() throws IOException {
        if (config.getOutfile().isAbsolute()) {
            return config.getOutfile().getParentFile();
        }

        File parent = config.getOutfile().getParentFile();
        return parent != null ? new File(configPath, parent.getPath()) : configPath;
    }

    public File getOutputFile() throws IOException {
        return config.getOutfile().isAbsolute()
                ? config.getOutfile()
                : new File(getOutputPath(), config.getOutfile().getName());
    }

    public void createBlank() {
        config = new Config();
        config.setJre(new Jre());
        configPath = null;
    }

    public void setAntConfig(Config config, File basedir) {
        this.config = config;
        configPath = basedir;
    }

    public void load(File file) throws ConfigPersisterException {
        try {
            DocumentBuilderFactory factory = DocumentBuilderFactory.newInstance();
            DocumentBuilder builder = factory.newDocumentBuilder();
            Document document = builder.parse(file);
            DOMImplementationLS implementation = (DOMImplementationLS) document.getImplementation();
            LSSerializer serializer = implementation.createLSSerializer();

            config = convertToCurrent(serializer.writeToString(document));
            setConfigPath(file);
        } catch (Exception e) {
            throw new ConfigPersisterException(e);
        }
    }

    public void save(File file) throws ConfigPersisterException {
        try {
            BufferedWriter writer = new BufferedWriter(
                    new OutputStreamWriter(new FileOutputStream(file), "UTF-8"));
            writer.write("<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n");
            xstream.toXML(config, writer);
            writer.close();
            setConfigPath(file);
        } catch (Exception e) {
            throw new ConfigPersisterException(e);
        }
    }

    private Config convertToCurrent(String configXml) {
        boolean requires64Bit = configXml.contains("<bundledJre64Bit>true</bundledJre64Bit>")
                || configXml.contains("<runtimeBits>64</runtimeBits>");

        String updatedConfigXml = configXml
                .replaceAll("<headerType>0<", "<headerType>gui<")
                .replaceAll("<headerType>1<", "<headerType>console<")
                .replaceAll("jarArgs>", "cmdLine>")
                .replaceAll("<jarArgs[ ]*/>", "<cmdLine/>")
                .replaceAll("args>", "opt>")
                .replaceAll("<args[ ]*/>", "<opt/>")
                .replaceAll("<jdkPreference>jdkOnly</jdkPreference>", "<requiresJdk>true</requiresJdk>")
                .replaceAll("<initialHeapSize>0</initialHeapSize>", "")
                .replaceAll("<maxHeapSize>0</maxHeapSize>", "")
                .replaceAll("<customProcName>.*</customProcName>", "")
                .replaceAll("<bundledJre64Bit>.*</bundledJre64Bit>", "")
                .replaceAll("<bundledJreAsFallback>.*</bundledJreAsFallback>", "")
                .replaceAll("<jdkPreference>.*</jdkPreference>", "")
                .replaceAll("<runtimeBits>.*</runtimeBits>", "");

        Config loadedConfig = (Config) xstream.fromXML(updatedConfigXml);

        if (Validator.isEmpty(loadedConfig.getJre().getPath())) {
            loadedConfig.getJre().setPath(Jre.DEFAULT_PATH);
        }
        if (requires64Bit) {
            loadedConfig.getJre().setRequires64Bit(true);
        }

        return loadedConfig;
    }

    private void setConfigPath(File configFile) {
        configPath = configFile.getAbsoluteFile().getParentFile();
    }
}
