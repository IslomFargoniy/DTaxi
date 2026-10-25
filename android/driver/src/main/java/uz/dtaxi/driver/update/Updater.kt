package uz.dtaxi.driver.update

import android.content.Context
import android.content.Intent
import android.net.Uri
import android.os.SystemClock
import android.provider.Settings
import android.widget.Toast
import kotlinx.coroutines.delay
import uz.dtaxi.driver.R
import androidx.core.content.FileProvider
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import okhttp3.OkHttpClient
import okhttp3.Request
import uz.dtaxi.driver.BuildConfig
import uz.dtaxi.driver.data.AppVersion
import java.io.File

/**
 * Google Play'siz yangilanish (qaror #37): APK firma serveridan yuklanadi va o'rnatish oynasi ochiladi.
 */
class Updater(private val context: Context, private val http: OkHttpClient) {
    fun isNewer(version: AppVersion) = version.url != null && version.versionCode > BuildConfig.VERSION_CODE

    /** Majburiy: joriy versiya API bilan mos emas — yangilamaguncha liniyaga chiqa olmaydi */
    fun isRequired(version: AppVersion) = isNewer(version) && BuildConfig.VERSION_CODE < version.minVersionCode

    /** @param onProgress 0..100 (hajm noma'lum bo'lsa -1) — yuklab olish oynasida ko'rsatiladi */
    suspend fun downloadAndInstall(version: AppVersion, onProgress: (Int) -> Unit = {}): Boolean = withContext(Dispatchers.IO) {
        val url = version.url ?: return@withContext false
        val dir = File(context.cacheDir, "updates").apply { mkdirs() }
        val file = File(dir, "driver-${version.versionCode}.apk")
        // Oldingi yangilanishlar APK'lari (har biri ~30 MB) keshda to'planib qolmasin
        dir.listFiles()?.filter { it != file }?.forEach { it.delete() }

        val absolute = if (url.startsWith("http")) url else uz.dtaxi.driver.DtaxiApp.instance.session.serverUrl + url
        val ok = runCatching {
            http.newCall(Request.Builder().url(absolute).build()).execute().use { response ->
                val body = response.body
                if (!response.isSuccessful || body == null) return@use false
                val total = body.contentLength()
                var done = 0L
                var lastPercent = -2
                body.byteStream().use { input ->
                    file.outputStream().use { output ->
                        val buffer = ByteArray(64 * 1024)
                        while (true) {
                            val read = input.read(buffer)
                            if (read < 0) break
                            output.write(buffer, 0, read)
                            done += read
                            val percent = if (total > 0) (done * 100 / total).toInt() else -1
                            if (percent != lastPercent) {
                                lastPercent = percent
                                withContext(Dispatchers.Main) { onProgress(percent) }
                            }
                        }
                    }
                }
                true
            }
        }.getOrDefault(false)
        if (!ok) return@withContext false

        withContext(Dispatchers.Main) {
            if (!context.packageManager.canRequestPackageInstalls()) {
                runCatching {
                    context.startActivity(
                        Intent(Settings.ACTION_MANAGE_UNKNOWN_APP_SOURCES, Uri.parse("package:${context.packageName}"))
                            .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK),
                    )
                }
                Toast.makeText(context, R.string.update_allow_install, Toast.LENGTH_LONG).show()
                // Ruxsat berilgach o'rnatish o'zi davom etadi — "Yangilash"ni qayta bosish shart emas
                val waitUntil = SystemClock.elapsedRealtime() + PERMISSION_WAIT_MS
                while (!context.packageManager.canRequestPackageInstalls()) {
                    if (SystemClock.elapsedRealtime() > waitUntil) return@withContext false
                    delay(500)
                }
            }
            install(file)
        }
    }

    /** O'rnatib bo'lingan (joriy yoki eskiroq versiya) APK'lar keshdan o'chiriladi */
    suspend fun deleteInstalled() = withContext(Dispatchers.IO) {
        File(context.cacheDir, "updates").listFiles()?.forEach { file ->
            val code = file.name.removePrefix("driver-").removeSuffix(".apk").toIntOrNull()
            if (code == null || code <= BuildConfig.VERSION_CODE) file.delete()
        }
    }

    private fun install(file: File): Boolean = runCatching {
        val uri = FileProvider.getUriForFile(context, "${context.packageName}.files", file)
        context.startActivity(
            Intent(Intent.ACTION_VIEW)
                .setDataAndType(uri, "application/vnd.android.package-archive")
                .addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION or Intent.FLAG_ACTIVITY_NEW_TASK),
        )
    }.isSuccess

    private companion object {
        const val PERMISSION_WAIT_MS = 3 * 60_000L
    }
}
