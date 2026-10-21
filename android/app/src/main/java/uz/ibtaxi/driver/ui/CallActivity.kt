package uz.ibtaxi.driver.ui

import android.os.Build
import android.media.AudioDeviceInfo
import uz.ibtaxi.driver.R
import uz.ibtaxi.driver.data.DriverState
import android.webkit.WebResourceError
import android.annotation.SuppressLint
import android.media.AudioManager
import android.net.Uri
import android.net.http.SslError
import android.os.Bundle
import android.view.WindowManager
import android.webkit.JavascriptInterface
import android.webkit.PermissionRequest
import android.webkit.SslErrorHandler
import android.webkit.WebChromeClient
import android.webkit.WebResourceRequest
import android.webkit.WebView
import android.webkit.WebViewClient
import androidx.activity.ComponentActivity
import uz.ibtaxi.driver.BuildConfig
import uz.ibtaxi.driver.IbTaxiApp

/**
 * Dispetcherga qo'ng'iroq (qaror #47): server sahifasi (JsSIP, WebRTC) WebView'da — internet orqali, bepul.
 * Token faqat o'z serverimiz sahifasiga JS ko'prik orqali beriladi.
 */
class CallActivity : ComponentActivity() {
    private lateinit var web: WebView
    private val audio by lazy { getSystemService(AUDIO_SERVICE) as AudioManager }

    // WebRTC uchun xavfsiz kontekst kerak: dev server http bo'lsa ham sahifa https orqali ochiladi
    private val callUrl = IbTaxiApp.instance.session.serverUrl.replaceFirst("http://", "https://") + "/driver-app/call"
    private val host = Uri.parse(callUrl).host

    /** Hozir ochiq sahifa (asosiy freym) — JS ko'prik oqimidan o'qiladi, WebView.url esa faqat UI oqimida */
    @Volatile
    private var pageUrl: Uri? = null

    @SuppressLint("SetJavaScriptEnabled")
    override fun attachBaseContext(base: android.content.Context) {
        super.attachBaseContext(uz.ibtaxi.driver.data.AppLocale.wrap(base, uz.ibtaxi.driver.IbTaxiApp.instance.session.locale))
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        window.addFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON)
        volumeControlStream = AudioManager.STREAM_VOICE_CALL
        audio.mode = AudioManager.MODE_IN_COMMUNICATION
        // Mashinada odatda karnay orqali gaplashiladi
        setSpeaker(true)

        // Lokal sinov build'ida qo'ng'iroq sahifasini Mac'dagi Chrome'dan kuzatish (chrome://inspect) — ovoz tashxisi
        WebView.setWebContentsDebuggingEnabled(BuildConfig.DEBUG || BuildConfig.SERVER_EDITABLE)
        web = WebView(this).apply {
            settings.javaScriptEnabled = true
            settings.domStorageEnabled = true
            settings.mediaPlaybackRequiresUserGesture = false
            addJavascriptInterface(Bridge(), "IbTaxi")
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
                    // Lokal sinov serveri (self-signed): debug yoki LAN build (server manzili qo'lda kiritiladi).
                    // Firma ilovasida faqat haqiqiy sertifikat — aks holda qora ekran o'rniga xabar
                    if (BuildConfig.DEBUG || BuildConfig.SERVER_EDITABLE) {
                        handler.proceed()
                    } else {
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

    /** Qo'ng'iroq sahifasi ochilmadi — qora ekranda qolib ketmasin */
    private fun failed() = runOnUiThread {
        DriverState.toast(getString(R.string.call_failed))
        finish()
    }

    /**
     * Ovoz karnay yoki qulog'ingizga tutiladigan dinamik orqali. Android 12+ da eski isSpeakerphoneOn
     * ko'p telefonlarda (Samsung) ishlamaydi — ovoz kichik dinamikka ketib, "jim" bo'lib qolardi.
     */
    private fun setSpeaker(on: Boolean) {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
            val type = if (on) AudioDeviceInfo.TYPE_BUILTIN_SPEAKER else AudioDeviceInfo.TYPE_BUILTIN_EARPIECE
            audio.availableCommunicationDevices.firstOrNull { it.type == type }?.let { audio.setCommunicationDevice(it) }
        } else {
            @Suppress("DEPRECATION")
            audio.isSpeakerphoneOn = on
        }
        // Ovoz balandligi: chaqiruv oqimi juda past bo'lsa — o'rtachaga
        val max = audio.getStreamMaxVolume(AudioManager.STREAM_VOICE_CALL)
        if (audio.getStreamVolume(AudioManager.STREAM_VOICE_CALL) < max / 2) {
            audio.setStreamVolume(AudioManager.STREAM_VOICE_CALL, max * 3 / 4, 0)
        }
    }

    override fun onDestroy() {
        web.destroy()
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
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
            return IbTaxiApp.instance.session.token ?: ""
        }

        @JavascriptInterface
        fun locale(): String = IbTaxiApp.instance.session.locale

        @JavascriptInterface
        fun close() = runOnUiThread { finish() }

        /** Sahifadagi karnay tugmasi */
        @JavascriptInterface
        fun speaker(on: Boolean) = runOnUiThread { setSpeaker(on) }
    }
}
