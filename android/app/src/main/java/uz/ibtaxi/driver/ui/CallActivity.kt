package uz.ibtaxi.driver.ui

import android.os.Build
import android.media.AudioDeviceInfo
import uz.ibtaxi.driver.R
import uz.ibtaxi.driver.data.DriverState
import android.webkit.WebResourceError
import android.annotation.SuppressLint
import android.content.Intent
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
    private var wantSpeaker = true
    private var routeListener: Any? = null

    // WebRTC uchun xavfsiz kontekst kerak: dev server http bo'lsa ham sahifa https orqali ochiladi
    private val callUrl = IbTaxiApp.instance.session.serverUrl.replaceFirst("http://", "https://") + "/driver-app/call"
    private val host = Uri.parse(callUrl).host

    /** Operator qo'ng'irog'iga javob (answer-{callId}); bo'sh — dispetcher navbatiga qo'ng'iroq */
    // singleTask: oyna ochiq turganda yangi qo'ng'iroq onNewIntent orqali keladi — qiymat yangilanadi
    @Volatile
    private var target = ""

    @Volatile
    private var caller = ""

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
        readTarget(intent)
        volumeControlStream = AudioManager.STREAM_VOICE_CALL
        audio.mode = AudioManager.MODE_IN_COMMUNICATION
        // Mashinada odatda karnay orqali gaplashiladi
        setSpeaker(true)
        // WebView ichidagi Chromium mikrofon ochilganda ovozni o'zi karnayga qaytaradi —
        // haydovchi tanlagan yo'nalish qayta qo'yiladi (aks holda quloq dinamigiga o'tib bo'lmasdi)
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
            val listener = AudioManager.OnCommunicationDeviceChangedListener { device ->
                if (device?.type != wantedType()) web.postDelayed({ applyRoute() }, 150)
            }
            audio.addOnCommunicationDeviceChangedListener(mainExecutor, listener)
            routeListener = listener
        }

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
                    // Faqat lokal tarmoq manzili — qo'lda kiritilgan ommaviy manzilda soxta sertifikat o'tib ketmasin.
                    // Firma ilovasida faqat haqiqiy sertifikat — aks holda qora ekran o'rniga xabar
                    if ((BuildConfig.DEBUG || BuildConfig.SERVER_EDITABLE) && isPrivateHost(Uri.parse(error.url).host)) {
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

    private fun readTarget(intent: Intent) {
        target = intent.getStringExtra(EXTRA_TARGET).orEmpty()
        caller = intent.getStringExtra(EXTRA_CALLER).orEmpty()
        // Qulflangan ekranda javob berilgan qo'ng'iroq qulf ortida qolib ketmasin
        if (target.isNotEmpty()) {
            setShowWhenLocked(true)
            setTurnScreenOn(true)
        }
    }

    /**
     * Qo'ng'iroq oynasi ochiq turganda operator qo'ng'irog'iga javob berildi: eski qo'ng'iroq sahifada
     * yopiladi (BYE yuborilishiga ulgursin), keyin sahifa yangi target bilan qayta ochiladi.
     */
    override fun onNewIntent(intent: Intent) {
        super.onNewIntent(intent)
        setIntent(intent)
        val next = intent.getStringExtra(EXTRA_TARGET).orEmpty()
        if (next.isNotEmpty() && next == target) return
        web.evaluateJavascript("window.ibtaxiHangup && window.ibtaxiHangup()") {
            web.postDelayed({
                if (isDestroyed) return@postDelayed
                readTarget(intent)
                web.loadUrl(callUrl)
            }, 600)
        }
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
        wantSpeaker = on
        applyRoute()
        // Mashina shovqinida eshitilsin: karnayda maksimum, quloq dinamigida 3/4 dan past emas
        val max = audio.getStreamMaxVolume(AudioManager.STREAM_VOICE_CALL)
        val target = if (on) max else max * 3 / 4
        if (audio.getStreamVolume(AudioManager.STREAM_VOICE_CALL) < target) {
            audio.setStreamVolume(AudioManager.STREAM_VOICE_CALL, target, 0)
        }
    }

    private fun wantedType() = if (wantSpeaker) AudioDeviceInfo.TYPE_BUILTIN_SPEAKER else AudioDeviceInfo.TYPE_BUILTIN_EARPIECE

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

    /** Tashxis uchun: ovoz hozir qayerga chiqyapti */
    private fun route(): String = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
        when (audio.communicationDevice?.type) {
            AudioDeviceInfo.TYPE_BUILTIN_SPEAKER -> "speaker"
            AudioDeviceInfo.TYPE_BUILTIN_EARPIECE -> "earpiece"
            null -> "none"
            else -> "type ${audio.communicationDevice?.type}"
        } + " mode ${audio.mode}"
    } else {
        @Suppress("DEPRECATION")
        (if (audio.isSpeakerphoneOn) "speaker" else "earpiece") + " mode ${audio.mode}"
    }

    override fun onDestroy() {
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
            return IbTaxiApp.instance.session.token ?: ""
        }

        @JavascriptInterface
        fun locale(): String = IbTaxiApp.instance.session.locale

        @JavascriptInterface
        fun close() = runOnUiThread { finish() }

        /** Sahifadagi karnay tugmasi */
        @JavascriptInterface
        fun speaker(on: Boolean) = runOnUiThread { setSpeaker(on) }

        @JavascriptInterface
        fun route(): String = this@CallActivity.route()

        @JavascriptInterface
        fun target(): String = this@CallActivity.target

        @JavascriptInterface
        fun caller(): String = this@CallActivity.caller
    }

    companion object {
        const val EXTRA_TARGET = "target"
        const val EXTRA_CALLER = "caller"

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
