package uz.ibtaxi.driver.update

import android.content.Context
import android.content.Intent
import android.net.Uri
import android.provider.Settings
import androidx.core.content.FileProvider
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import okhttp3.OkHttpClient
import okhttp3.Request
import uz.ibtaxi.driver.BuildConfig
import uz.ibtaxi.driver.data.AppVersion
import java.io.File

/**
 * Google Play'siz yangilanish (qaror #37): APK firma serveridan yuklanadi va o'rnatish oynasi ochiladi.
 */
class Updater(private val context: Context, private val http: OkHttpClient) {
    fun isNewer(version: AppVersion) = version.url != null && version.versionCode > BuildConfig.VERSION_CODE

    /** Majburiy: joriy versiya API bilan mos emas — yangilamaguncha liniyaga chiqa olmaydi */
    fun isRequired(version: AppVersion) = isNewer(version) && BuildConfig.VERSION_CODE < version.minVersionCode

    suspend fun downloadAndInstall(version: AppVersion): Boolean = withContext(Dispatchers.IO) {
        val url = version.url ?: return@withContext false
        val dir = File(context.cacheDir, "updates").apply { mkdirs() }
        val file = File(dir, "driver-${version.versionCode}.apk")

        val absolute = if (url.startsWith("http")) url else uz.ibtaxi.driver.IbTaxiApp.instance.session.serverUrl + url
        http.newCall(Request.Builder().url(absolute).build()).execute().use { response ->
            if (!response.isSuccessful) return@withContext false
            response.body?.byteStream()?.use { input -> file.outputStream().use { input.copyTo(it) } } ?: return@withContext false
        }

        withContext(Dispatchers.Main) {
            if (!context.packageManager.canRequestPackageInstalls()) {
                context.startActivity(
                    Intent(Settings.ACTION_MANAGE_UNKNOWN_APP_SOURCES, Uri.parse("package:${context.packageName}"))
                        .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK),
                )
            } else {
                val uri = FileProvider.getUriForFile(context, "${context.packageName}.files", file)
                context.startActivity(
                    Intent(Intent.ACTION_VIEW)
                        .setDataAndType(uri, "application/vnd.android.package-archive")
                        .addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION or Intent.FLAG_ACTIVITY_NEW_TASK),
                )
            }
        }
        true
    }
}
