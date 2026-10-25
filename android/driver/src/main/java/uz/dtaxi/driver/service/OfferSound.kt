package uz.dtaxi.driver.service

import android.content.Context
import android.media.AudioAttributes
import android.media.Ringtone
import android.media.RingtoneManager
import android.net.Uri
import android.os.Build
import android.os.Handler
import android.os.Looper

/**
 * Taklif signali — yagona egasi. Ilgari bildirishnoma kanali ham, taklif oynasi ham alohida signal chalardi
 * (ikkita ovoz bir vaqtda). Endi bildirishnoma jim, ovozni faqat shu obyekt boshqaradi.
 */
object OfferSound {
    // Kerak bo'lgandagina — unit testlarda (DriverState.clearOffer) Looper yo'q
    private val handler by lazy { Handler(Looper.getMainLooper()) }
    private var ringtone: Ringtone? = null
    private var offerId: Int? = null
    private val autoStop = Runnable { stop() }

    /** Bir taklif uchun bir marta: push, Reverb va oyna bir vaqtda chaqirsa ham ovoz bitta */
    @Synchronized
    fun start(context: Context, offerId: Int, expiresInSeconds: Int) {
        if (this.offerId == offerId && ringtone?.isPlaying == true) {
            // Qayta yuborilgan taklif — signal yangi muddatgacha chalinadi
            handler.removeCallbacks(autoStop)
            handler.postDelayed(autoStop, expiresInSeconds.coerceAtLeast(1) * 1000L)
            return
        }
        stop()
        this.offerId = offerId
        ringtone = RingtoneManager.getRingtone(context.applicationContext, soundUri(context))?.apply {
            audioAttributes = AudioAttributes.Builder().setUsage(AudioAttributes.USAGE_ALARM).build()
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.P) isLooping = true
            play()
        }
        handler.postDelayed(autoStop, expiresInSeconds.coerceAtLeast(1) * 1000L)
    }

    /** Haydovchi sozlamalarda tanlagan ovoz (o'chirilgan/yo'q fayl bo'lsa — standart budilnik) */
    fun soundUri(context: Context): Uri {
        val chosen = uz.dtaxi.driver.DtaxiApp.instance.session.offerSound?.let(Uri::parse)
            ?.takeIf { uri -> runCatching { context.contentResolver.openAssetFileDescriptor(uri, "r")?.use { true } }.getOrNull() == true }

        return chosen ?: defaultUri()
    }

    fun defaultUri(): Uri = RingtoneManager.getDefaultUri(RingtoneManager.TYPE_ALARM)
        ?: RingtoneManager.getDefaultUri(RingtoneManager.TYPE_RINGTONE)

    @Synchronized
    fun stop() {
        if (ringtone == null && offerId == null) return
        handler.removeCallbacks(autoStop)
        ringtone?.stop()
        ringtone = null
        offerId = null
    }
}
