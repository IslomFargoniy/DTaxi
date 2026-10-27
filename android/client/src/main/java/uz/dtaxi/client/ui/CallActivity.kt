package uz.dtaxi.client.ui

import android.annotation.SuppressLint
import android.content.Context
import android.content.Intent
import android.media.AudioDeviceInfo
import android.media.AudioManager
import android.net.Uri
import android.net.http.SslError
import android.os.Build
import android.os.Bundle
import android.view.WindowManager
import android.webkit.JavascriptInterface
import android.webkit.PermissionRequest
import android.webkit.SslErrorHandler
import android.webkit.WebChromeClient
import android.webkit.WebResourceError
import android.webkit.WebResourceRequest
import android.webkit.WebView
import android.webkit.WebViewClient
import android.widget.Toast
import androidx.activity.ComponentActivity
import uz.dtaxi.client.BuildConfig
import uz.dtaxi.client.ClientApp
import uz.dtaxi.client.R
import uz.dtaxi.core.data.AppLocale

/**
 * Haydovchi yoki dispetcherga ilova ichidan qo'ng'iroq (qaror #72): server sahifasi (JsSIP, WebRTC) WebView'da —
 * internet orqali, trunk daqiqasi sarflanmaydi, raqamlar yashirin. Server har qo'ng'iroqqa vaqtinchalik ichki raqam beradi.
 * Token faqat o'z serverimiz sahifasiga JS ko'prik orqali beriladi.
 */
class CallActivity : ComponentActivity() {
    private lateinit var web: WebView
    private val audio by lazy { getSystemService(AUDIO_SERVICE) as AudioManager }

    // Mijoz telefonni qulog'iga tutadi — standart quloq dinamigi
    private var wantSpeaker = false
    private var routeListener: Any? = null

    // WebRTC uchun xavfsiz kontekst kerak: sinov serveri http bo'lsa ham sahifa https orqali ochiladi
    private val callUrl = BuildConfig.SERVER_URL.replaceFirst("http://", "https://") + "/client-app/call"
    private val host = Uri.parse(callUrl).host

    private var target = "dispatcher"
    private var title = ""

    /** Hozir ochiq sahifa (asosiy freym) — JS ko'prik oqimidan o'qiladi, WebView.url esa faqat UI oqimida */
    @Volatile
    private var pageUrl: Uri? = null

    override fun attachBaseContext(base: Context) {
        super.attachBaseContext(AppLocale.wrap(base, ClientApp.instance.session.locale))
    }

    @SuppressLint("SetJavaScriptEnabled")
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        window.addFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON)
        target = if (intent.getStringExtra(EXTRA_TARGET) == "driver") "driver" else "dispatcher"
        title = intent.getStringExtra(EXTRA_TITLE).orEmpty()
        volumeControlStream = AudioManager.STREAM_VOICE_CALL
        audio.mode = AudioManager.MODE_IN_COMMUNICATION
        applyRoute()
        // WebView ichidagi Chromium mikrofon ochilganda ovozni o'zi karnayga o'tkazishi mumkin — tanlangani qaytariladi
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
            val listener = AudioManager.OnCommunicationDeviceChangedListener { device ->
                if (device?.type != wantedType()) web.postDelayed({ applyRoute() }, 150)
            }
            audio.addOnCommunicationDeviceChangedListener(mainExecutor, listener)
            routeListener = listener
        }

        WebView.setWebContentsDebuggingEnabled(BuildConfig.DEBUG)
        web = WebView(this).apply {
            settings.javaScriptEnabled = true
            settings.domStorageEnabled = true
            settings.mediaPlaybackRequiresUserGesture = false
            addJavascriptInterface(Bridge(), "Dtaxi")
            webChromeClient = object : WebChromeClient() {
                override fun onPermissionRequest(request: PermissionRequest) {
                    runOnUiThread {
                        val audioOnly = request.resources.filter { it == PermissionRequest.RESOURCE_AUDIO_CAPTURE }
                        if (request.origin.host == host && audioOnly.isNotEmpty()) request.grant(audioOnly.toTypedArray()) else request.deny()
                    }
                }
            }
            webViewClient = object : WebViewClient() {
                // Boshqa saytga o'tish taqiqlanadi (token sizib chiqmasligi uchun)
                override fun shouldOverrideUrlLoading(view: WebView, request: WebResourceRequest) = request.url.host != host

                override fun onPageStarted(view: WebView, url: String?, favicon: android.graphics.Bitmap?) {
                    pageUrl = url?.let(Uri::parse)
                }

                override fun doUpdateVisitedHistory(view: WebView, url: String?, isReload: Boolean) {
                    pageUrl = url?.let(Uri::parse)
                }

                @SuppressLint("WebViewClientOnReceivedSslError")
                override fun onReceivedSslError(view: WebView, handler: SslErrorHandler, error: SslError) {
                    // Faqat debug build'da lokal tarmoq serveriga (self-signed); firma ilovasida faqat haqiqiy sertifikat
                    if (BuildConfig.DEBUG && isPrivateHost(Uri.parse(error.url).host)) handler.proceed() else {
                        handler.cancel()
                        failed()
                    }
                }

                override fun onReceivedError(view: WebView, request: WebResourceRequest, error: WebResourceError) {
                    if (request.isForMainFrame) failed()
                }
            }
        }
        setContentView(web)
        web.loadUrl(callUrl)
    }

    /** Qo'ng'iroq sahifasi ochilmadi — bo'sh ekranda qolib ketmasin */
    private fun failed() = runOnUiThread {
        Toast.makeText(this, R.string.call_failed, Toast.LENGTH_LONG).show()
        finish()
    }

    private fun wantedType() = if (wantSpeaker) AudioDeviceInfo.TYPE_BUILTIN_SPEAKER else AudioDeviceInfo.TYPE_BUILTIN_EARPIECE

    /** Android 12+ da eski isSpeakerphoneOn ko'p telefonlarda (Samsung) ishlamaydi */
    private fun applyRoute() {
        if (isDestroyed) return
        if (audio.mode != AudioManager.MODE_IN_COMMUNICATION) audio.mode = AudioManager.MODE_IN_COMMUNICATION
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
            if (audio.communicationDevice?.type != wantedType()) {
                audio.availableCommunicationDevices.firstOrNull { it.type == wantedType() }?.let { audio.setCommunicationDevice(it) }
            }
        } else {
            @Suppress("DEPRECATION")
            audio.isSpeakerphoneOn = wantSpeaker
        }
    }

    override fun onDestroy() {
        // Sahifa yopilishi bilan WebRTC ham to'xtaydi — Asterisk BYE'ni kanal uzilishidan biladi
        web.destroy()
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
            (routeListener as? AudioManager.OnCommunicationDeviceChangedListener)?.let { audio.removeOnCommunicationDeviceChangedListener(it) }
            audio.clearCommunicationDevice()
        } else {
            @Suppress("DEPRECATION")
            audio.isSpeakerphoneOn = false
        }
        audio.mode = AudioManager.MODE_NORMAL
        super.onDestroy()
    }

    private inner class Bridge {
        @JavascriptInterface
        fun token(): String {
            // Token faqat o'z serverimiz sahifasiga (https) — boshqa sahifa ochilib qolsa ham bermaydi
            val page = pageUrl
            if (page?.scheme != "https" || page.host != host) return ""
            return ClientApp.instance.session.token ?: ""
        }

        @JavascriptInterface
        fun locale(): String = ClientApp.instance.session.locale

        @JavascriptInterface
        fun close() = runOnUiThread { finish() }

        @JavascriptInterface
        fun speaker(on: Boolean) = runOnUiThread {
            wantSpeaker = on
            applyRoute()
        }

        @JavascriptInterface
        fun target(): String = this@CallActivity.target

        @JavascriptInterface
        fun title(): String = this@CallActivity.title
    }

    companion object {
        const val EXTRA_TARGET = "target"
        const val EXTRA_TITLE = "title"

        fun intent(context: Context, target: String, title: String? = null): Intent =
            Intent(context, CallActivity::class.java).putExtra(EXTRA_TARGET, target).putExtra(EXTRA_TITLE, title.orEmpty())

        /** 192.168.x, 10.x, 172.16-31.x va *.local — faqat shu manzillarda self-signed sertifikatga ruxsat */
        fun isPrivateHost(host: String?): Boolean {
            if (host.isNullOrEmpty()) return false
            if (host.endsWith(".local", ignoreCase = true)) return true
            val parts = host.split('.').map { it.toIntOrNull() ?: return false }
            if (parts.size != 4 || parts.any { it !in 0..255 }) return false
            return parts[0] == 10 ||
                (parts[0] == 192 && parts[1] == 168) ||
                (parts[0] == 172 && parts[1] in 16..31)
        }
    }
}
