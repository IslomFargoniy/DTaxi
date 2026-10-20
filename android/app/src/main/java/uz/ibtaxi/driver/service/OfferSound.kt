package uz.ibtaxi.driver.service

import android.content.Context
import android.media.AudioAttributes
import android.media.Ringtone
import android.media.RingtoneManager
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
        if (this.offerId == offerId && ringtone?.isPlaying == true) return
        stop()
        this.offerId = offerId
        ringtone = RingtoneManager.getRingtone(context.applicationContext, RingtoneManager.getDefaultUri(RingtoneManager.TYPE_ALARM))?.apply {
            audioAttributes = AudioAttributes.Builder().setUsage(AudioAttributes.USAGE_ALARM).build()
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.P) isLooping = true
            play()
        }
        handler.postDelayed(autoStop, expiresInSeconds.coerceAtLeast(1) * 1000L)
    }

    @Synchronized
    fun stop() {
        if (ringtone == null && offerId == null) return
        handler.removeCallbacks(autoStop)
        ringtone?.stop()
        ringtone = null
        offerId = null
    }
}
