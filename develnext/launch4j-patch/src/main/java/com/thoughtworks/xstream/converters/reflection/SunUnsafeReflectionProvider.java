/*
 * Local Launch4j toolchain compatibility override.
 *
 * XStream 1.4.21 probes this provider during JVM class initialization even
 * when the caller explicitly supplies PureJavaReflectionProvider. On JDK 25
 * that probe emits a terminal-deprecation warning for sun.misc.Unsafe.
 */
package com.thoughtworks.xstream.converters.reflection;

/**
 * Makes XStream's optional Unsafe capability probe fail cleanly. XStream then
 * selects its own PureJavaReflectionProvider fallback.
 */
public class SunUnsafeReflectionProvider {
    public SunUnsafeReflectionProvider() {
        throw disabled();
    }

    public SunUnsafeReflectionProvider(FieldDictionary fieldDictionary) {
        throw disabled();
    }

    private static ObjectAccessException disabled() {
        return new ObjectAccessException("Unsafe reflection is disabled for the Launch4j toolchain");
    }
}
