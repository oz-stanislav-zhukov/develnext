# DevLine modernization inventory

Stable baseline: root `09d3c86e`, branch `modernization/java25`.

## Completed modernization groups

| Group | Result |
|---|---|
| Source graph | JPHP `8feff4fb` and Wizard Framework `f7c303a` are built from the checked-out sources and published into `.gradle/source-repo`; the build no longer requires artifacts from the user's Maven Local repository. |
| RichTextFX | Source checkout updated to tag `v0.11.7` (`428b292`) and compiled by the root `richtextfx-source` project. |
| Build system | Root, JPHP, and Wizard wrappers use Gradle `9.7.1`; obsolete dependency configurations and active JCenter/Bintray repositories were removed. |
| Java | Compilation, Gradle toolchains, distribution, and launcher target Java `25`; the Windows distribution includes Temurin `25.0.4.1+1`. |
| JavaFX | Explicit Windows OpenJFX `25.0.4` modules: base, graphics, controls, fxml, media, web, and swing. |
| UI/runtime libraries | JFoenix `9.0.10`, ANTLR runtime `4.13.2`, and SLF4J Simple `1.7.36`. |

## Remaining legacy dependency groups

These are intentionally left as isolated follow-up groups. Updating them all in one pass would mix protocol, JDBC, bytecode, and native-runtime risks with the now-working Java 25/JavaFX 25 baseline.

| Group | Current legacy versions | Why it needs a separate update |
|---|---|---|
| HTTP server | Jetty `9.4.3.v20170317` | Old `javax.*` generation; a current Jetty line requires API and behavior migration. |
| Connection pool | `HikariCP-java6` `2.3.7` | Obsolete Java 6 artifact; update together with SQL lifecycle and pool smoke tests. |
| JDBC/native drivers | MySQL `5.1.41`, PostgreSQL `9.4.1212.jre7`, SQLite `3.16.1`, Jaybird JDK 18 `3.0.1`, JNA `4.4.0` | Driver class names, URL defaults, native loading, and database compatibility must be tested per bundle. |
| Parsers/data | Gson `2.7`, jsoup `1.10.2`, SnakeYAML `1.19`, Commons Email `1.3.3` | Behavioral and security changes can alter existing project parsing or network behavior. |
| Bytecode/Git/archive/physics | ASM All `5.2`, JGit `4.7.0`, ZT-Zip `1.11`, dyn4j `3.2.1` | ASM needs replacement of the retired aggregate artifact; the others require focused compatibility tests. |
| Packaging/native tools | Bundled Launch4j `3.9`, tracked browser/tool/native jars, and remaining `fileTree` dependencies | Replace only after launcher and platform-specific packaging are verified with equivalent outputs. |

## Known non-fatal follow-ups

- JavaFX currently runs from the classpath and reports the standard unnamed-module warning; module-path conversion is a separate packaging change.
- Gradle reports APIs that will become incompatible with Gradle 10; Gradle 9.7.1 completes the current source graph and distribution build.
- Some old projects reference the unavailable `GradleProjectBehaviour`; opening remains resilient and reports the missing behavior instead of crashing the IDE.
- The historical online DevLine API can time out while offline; local editing and designer startup do not depend on a successful response.
- OSS snapshot repository declarations remain only in bundled Launch4j Maven metadata and the upstream RichTextFX build. Neither is an active repository of the root Gradle build; RichTextFX is compiled through `richtextfx-source`.

## Recommended next order

1. JDBC drivers and HikariCP with per-database connection tests.
2. Jetty HTTP/WebSocket stack as one API-migration group.
3. ASM/JGit/parser libraries with project import and code-analysis fixtures.
4. Launch4j and tracked native/tool jars with clean-machine packaging verification.
