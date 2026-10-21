package uz.ibtaxi.driver.service

import android.app.ActivityManager
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import android.media.AudioAttributes
import android.media.AudioManager
import android.media.Ringtone
import android.media.RingtoneManager
import android.os.Build
import android.os.Handler
import android.os.Looper
import android.os.VibrationEffect
import android.os.Vibrator
import android.os.VibratorManager
import androidx.core.app.NotificationCompat
import androidx.core.app.Person
import kotlinx.coroutines.Job
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.launch
import uz.ibtaxi.driver.IbTaxiApp
import uz.ibtaxi.driver.R
import uz.ibtaxi.driver.data.AppLocale
import uz.ibtaxi.driver.data.IncomingCall
import uz.ibtaxi.driver.ui.IncomingCallActivity

/**
 * Operator qo'ng'irog'i (qaror #59): telefon qo'ng'irog'idek — to'liq ekranli CallStyle bildirishnoma,
 * telefonning qo'ng'iroq ohangi va tebranish. Taklif signali (OfferSound) bilan aralashmaydi — alohida egasi.
 * Reverb va FCM ikkalasi ham chaqiradi: bitta qo'ng'iroq bir marta jiringlaydi.
 */
object IncomingCallNotifier {
    private const val NOTIFICATION_ID = 2003
    const val ACTION_DECLINE = "uz.ibtaxi.driver.DECLINE_CALL"

    private val handler by lazy { Handler(Looper.getMainLooper()) }
    private val state = MutableStateFlow<IncomingCall?>(null)

    /** Hozir jiringlayotgan qo'ng'iroq (ekran shunga qarab yopiladi) */
    val current: StateFlow<IncomingCall?> = state.asStateFlow()

    /** Javob berilgan/rad etilgan/tugagan — kechikkan push qayta jiringlatmasin */
    private val handled = ArrayDeque<Int>()
    private var ringtone: Ringtone? = null
    private var vibrator: Vibrator? = null
    private var appContext: Context? = null
    private val expire = Runnable { appContext?.let { context -> state.value?.let { ended(context, it.callId) } } }

    @Synchronized
    fun show(context: Context, call: IncomingCall) {
        if (call.callId in handled || state.value?.callId == call.callId || call.expiresIn <= 1) return
        val app = context.applicationContext
        appContext = app
        stopAlerts()
        state.value = call
        notify(app, call)
        startAlerts(app)
        handler.removeCallbacks(expire)
        handler.postDelayed(expire, call.expiresIn * 1000L)
        // Ilova ekranda bo'lsa tizim to'liq ekranni ochmaydi (faqat yuqoridan banner) — o'zimiz ochamiz
        if (inForeground()) runCatching { app.startActivity(IncomingCallActivity.intent(app)) }
    }

    /** Server: operator qo'ydi yoki vaqt o'tdi */
    @Synchronized
    fun ended(context: Context, callId: Int) {
        remember(callId)
        if (state.value?.callId == callId) clear(context)
    }

    /** "Javob berish": jiringlash to'xtaydi, qo'ng'iroq sahifasi shu qo'ng'iroq bilan ochiladi */
    @Synchronized
    fun answer(context: Context): IncomingCall? {
        val call = state.value ?: return null
        remember(call.callId)
        clear(context)
        return call
    }

    /** "Rad etish": operator band signalini eshitadi */
    @Synchronized
    fun decline(context: Context): Job? {
        val call = state.value ?: return null
        remember(call.callId)
        clear(context)
        val app = context.applicationContext as IbTaxiApp
        return app.scope.launch { app.repository.declineCall(call.callId) }
    }

    /** Ovoz tugmasi: telefon kabi faqat ohang va tebranish o'chadi, qo'ng'iroq davom etadi */
    @Synchronized
    fun silence() = stopAlerts()

    private fun clear(context: Context) {
        handler.removeCallbacks(expire)
        stopAlerts()
        state.value = null
        runCatching { context.getSystemService(NotificationManager::class.java).cancel(NOTIFICATION_ID) }
    }

    private fun remember(callId: Int) {
        if (callId in handled) return
        handled.addLast(callId)
        if (handled.size > 20) handled.removeFirst()
    }

    private fun notify(app: Context, call: IncomingCall) {
        // FCM jarayonida kontekst ilova tilida emas
        val context = AppLocale.wrap(app, IbTaxiApp.instance.session.locale)
        val name = call.operator.ifBlank { context.getString(R.string.dispatcher) }
        val flags = PendingIntent.FLAG_IMMUTABLE or PendingIntent.FLAG_UPDATE_CURRENT
        val screen = PendingIntent.getActivity(app, 10, IncomingCallActivity.intent(app), flags)
        val answer = PendingIntent.getActivity(app, 11, IncomingCallActivity.intent(app).setAction(IncomingCallActivity.ACTION_ANSWER), flags)
        val decline = PendingIntent.getBroadcast(
            app,
            12,
            Intent(app, IncomingCallReceiver::class.java).setAction(ACTION_DECLINE),
            flags,
        )
        val caller = Person.Builder().setName(name).setImportant(true).build()
        val notification = NotificationCompat.Builder(app, IbTaxiApp.CHANNEL_CALLS)
            .setSmallIcon(R.drawable.ic_notification)
            .setContentTitle(name)
            .setContentText(context.getString(R.string.incoming_call))
            .setCategory(NotificationCompat.CATEGORY_CALL)
            .setPriority(NotificationCompat.PRIORITY_MAX)
            .setVisibility(NotificationCompat.VISIBILITY_PUBLIC)
            .setOngoing(true)
            .setFullScreenIntent(screen, true)
            .setContentIntent(screen)
            .setTimeoutAfter(call.expiresIn * 1000L)
            // Android 12+ — tizimning qo'ng'iroq ko'rinishi; eskilarida compat oddiy tugmalar qo'yadi
            .setStyle(NotificationCompat.CallStyle.forIncomingCall(caller, decline, answer))
            .build()
        runCatching { app.getSystemService(NotificationManager::class.java).notify(NOTIFICATION_ID, notification) }
    }

    private fun startAlerts(context: Context) {
        val attributes = AudioAttributes.Builder()
            .setUsage(AudioAttributes.USAGE_NOTIFICATION_RINGTONE)
            .setContentType(AudioAttributes.CONTENT_TYPE_SONIFICATION)
            .build()
        // Telefonning o'z qo'ng'iroq ohangi: jimjit rejimda tizim o'zi o'chiradi (STREAM_RING)
        ringtone = runCatching {
            RingtoneManager.getRingtone(context, RingtoneManager.getDefaultUri(RingtoneManager.TYPE_RINGTONE))?.apply {
                audioAttributes = attributes
                isLooping = true
                play()
            }
        }.getOrNull()
        val audio = context.getSystemService(AudioManager::class.java)
        if (audio?.ringerMode != AudioManager.RINGER_MODE_SILENT) {
            vibrator = vibrator(context)?.also {
                runCatching { it.vibrate(VibrationEffect.createWaveform(longArrayOf(0, 1000, 1000), 0), attributes) }
            }
        }
    }

    private fun stopAlerts() {
        runCatching { ringtone?.stop() }
        ringtone = null
        runCatching { vibrator?.cancel() }
        vibrator = null
    }

    private fun vibrator(context: Context): Vibrator? = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
        context.getSystemService(VibratorManager::class.java)?.defaultVibrator
    } else {
        @Suppress("DEPRECATION")
        context.getSystemService(Vibrator::class.java)
    }

    /** Ilova ekrani ko'rinib turibdimi (foreground servis bu hisobga kirmaydi) */
    private fun inForeground(): Boolean {
        val info = ActivityManager.RunningAppProcessInfo()
        ActivityManager.getMyMemoryState(info)
        return info.importance == ActivityManager.RunningAppProcessInfo.IMPORTANCE_FOREGROUND
    }
}
