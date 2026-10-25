import org.gradle.api.GradleException
import org.gradle.api.Project
import org.gradle.api.file.Directory
import org.gradle.api.provider.Provider
import java.io.File
import java.util.Properties

/**
 * Firma konfiguratsiyasi (qaror #35, #71): -Pfirm=<papka>, standart — lokal sinov uchun "dev".
 * Haydovchi va mijoz ilovalari bir xil firms/<firma>/ dan o'qiydi: firm.properties, keystore.jks, dev-ca.pem.
 */
class Firm(private val project: Project) {
    val name: String = (project.findProperty("firm") as String?) ?: "dev"
    val dir: File = project.rootProject.file("firms/$name")

    private val props = load(File(dir, "firm.properties")).also {
        require(it.isNotEmpty()) { "firms/$name/firm.properties topilmadi" }
    }
    val keystore: Properties = load(File(dir, "keystore.properties"))

    /** Debug kalit faqat lokal sinov ilovalarida — firma ilovasi keyin o'z kaliti bilan chiqsa, eski ilova ustiga yangilanmaydi */
    val debugSigningAllowed: Boolean = name in setOf("dev", "lan")
    val signingReady: Boolean = keystore.isNotEmpty() && File(dir, "keystore.jks").exists()

    fun prop(key: String, default: String = ""): String = props.getProperty(key, default).trim()

    /** Release so'ralganda firma kaliti bo'lmasa — to'xtatish (debug kalit bilan chiqib qolmasin) */
    fun requireReleaseSigning() {
        project.gradle.taskGraph.whenReady {
            val releaseRequested = allTasks.any { it.project == project && it.name.contains("Release") }
            if (releaseRequested && !signingReady && !debugSigningAllowed) {
                throw GradleException(
                    "firms/$name/keystore.jks yoki keystore.properties topilmadi — firma ilovasi faqat o'z kaliti bilan " +
                        "imzolanadi (firms/README.md). Debug kalit faqat -Pfirm=dev/lan uchun.",
                )
            }
        }
    }

    /**
     * Tarmoq xavfsizligi: cleartext faqat http serverda (lokal sinov); firms/<firm>/dev-ca.pem bo'lsa — lokal sinov
     * serverining self-signed sertifikatiga faqat debug build ishonadi. Natija — qo'shimcha res papkasi.
     */
    fun registerNetworkSecurity(): Provider<Directory> {
        val devCa = File(dir, "dev-ca.pem")
        val outDir = project.layout.buildDirectory.dir("generated/firmSecurityRes")
        val cleartext = prop("serverUrl").startsWith("http://")
        val task = project.tasks.register("generateNetworkSecurity") {
            inputs.property("cleartext", cleartext)
            inputs.property("devCa", if (devCa.exists()) devCa.readText() else "")
            outputs.dir(outDir)
            doLast {
                val res = outDir.get().asFile
                res.deleteRecursively()
                File(res, "xml").mkdirs()
                val debugAnchor = if (devCa.exists()) {
                    File(res, "raw").mkdirs()
                    devCa.copyTo(File(res, "raw/dev_ca.pem"), overwrite = true)
                    "<certificates src=\"@raw/dev_ca\" />"
                } else {
                    ""
                }
                File(res, "xml/network_security_config.xml").writeText(
                    """
                    <?xml version="1.0" encoding="utf-8"?>
                    <network-security-config>
                        <base-config cleartextTrafficPermitted="$cleartext">
                            <trust-anchors><certificates src="system" /></trust-anchors>
                        </base-config>
                        <debug-overrides>
                            <trust-anchors>$debugAnchor<certificates src="system" /></trust-anchors>
                        </debug-overrides>
                    </network-security-config>
                    """.trimIndent(),
                )
            }
        }
        project.tasks.matching { it.name == "preBuild" }.configureEach { dependsOn(task) }
        return outDir
    }

    companion object {
        /**
         * Avtomatik versiya: git commit'lar soni (har yangi commit — kattaroq raqam, ilova avto-yangilanishi uchun).
         * paths berilsa — faqat shu papkalarni o'zgartirgan commit'lar (mijoz ilovasi o'z raqami bilan, 0 dan).
         */
        fun buildNumber(project: Project, vararg paths: String): Int = runCatching {
            val command = listOf("git", "rev-list", "--count", "HEAD") + (if (paths.isEmpty()) emptyList() else listOf("--", *paths))
            ProcessBuilder(command).directory(project.rootDir).start()
                .inputStream.bufferedReader().readText().trim().toInt()
        }.getOrDefault(1)

        private fun load(file: File) = Properties().apply {
            if (file.exists()) file.inputStream().use { load(it) }
        }
    }
}
