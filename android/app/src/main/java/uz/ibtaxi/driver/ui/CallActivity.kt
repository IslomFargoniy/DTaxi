package uz.ibtaxi.driver.ui

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
        @Suppress("DEPRECATION")
        audio.isSpeakerphoneOn = true

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

                @SuppressLint("WebViewClientOnReceivedSslError")
                override fun onReceivedSslError(view: WebView, handler: SslErrorHandler, error: SslError) {
                    // Faqat lokal sinov serveri (self-signed sertifikat); firma serverida haqiqiy sertifikat
                    if (BuildConfig.DEBUG) handler.proceed() else handler.cancel()
                }
            }
        }
        setContentView(web)
        web.loadUrl(callUrl)
    }

    override fun onDestroy() {
        web.destroy()
        @Suppress("DEPRECATION")
        audio.isSpeakerphoneOn = false
        audio.mode = AudioManager.MODE_NORMAL
        super.onDestroy()
    }

    private inner class Bridge {
        @JavascriptInterface
        fun token(): String = IbTaxiApp.instance.session.token ?: ""

        @JavascriptInterface
        fun locale(): String = IbTaxiApp.instance.session.locale

        @JavascriptInterface
        fun close() = runOnUiThread { finish() }
    }
}
