package uz.dtaxi.driver

import android.app.Application
import android.app.NotificationChannel
import android.app.NotificationManager
import android.os.SystemClock
import com.google.firebase.FirebaseApp
import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.collectLatest
import kotlinx.coroutines.launch
import com.google.firebase.FirebaseOptions
import org.maplibre.android.MapLibre
import uz.dtaxi.driver.data.ApiClient
import uz.dtaxi.driver.data.Repository
import uz.dtaxi.driver.data.DriverState
import uz.dtaxi.driver.data.Session
import uz.dtaxi.driver.service.OfferNotifier

class DtaxiApp : Application() {
    /** Ekranlardan mustaqil amallar: buyurtma kartochkasi yopilsa ham so'rov bekor bo'lmaydi */
    val scope = kotlinx.coroutines.CoroutineScope(kotlinx.coroutines.SupervisorJob() + kotlinx.coroutines.Dispatchers.Main)

    lateinit var session: Session
        private set
    lateinit var repository: Repository
        private set

    override fun onCreate() {
        super.onCreate()
        instance = this
        session = Session(this)
        uz.dtaxi.driver.data.ServerClock.init(session.clockOffsetMs) { session.clockOffsetMs = it }
        repository = Repository(ApiClient(session), session)
        // Chiqish yoki 401: liniya servisi to'xtaydi, eski trek va taksometr keyingi haydovchiga o'tmaydi
        repository.onSignedOut = {
            uz.dtaxi.driver.service.DriverService.stop(this)
            uz.dtaxi.driver.service.DriverService.clearLocalData(this)
            OfferNotifier.cancel(this)
            DriverState.clearOffer()
        }
        uz.dtaxi.driver.data.DriverState.setThemeMode(session.themeMode)
        MapLibre.getInstance(this)
        initFirebase()
        createChannels()
        expireOffers()
    }

    /**
     * Muddati o'tgan taklif ekran ochilmagan bo'lsa ham o'chiriladi (servis yoki FCM jarayoni) —
     * keyin ilova ochilganda eski taklif chiqib qolmasin.
     */
    private fun expireOffers() = scope.launch {
        DriverState.offer.collectLatest { offer ->
            offer ?: return@collectLatest
            delay(offer.millisLeft(SystemClock.elapsedRealtime()))
            DriverState.expireOffer(offer)
            if (DriverState.offer.value == null) OfferNotifier.cancel(this@DtaxiApp)
        }
    }

    /**
     * Firebase — firmaning o'z loyihasi (qaror #35). Sozlanmagan bo'lsa push o'chiq, faqat Reverb ishlaydi.
     */
    private fun initFirebase() {
        if (BuildConfig.FIREBASE_PROJECT_ID.isBlank() || BuildConfig.FIREBASE_APPLICATION_ID.isBlank()) return
        if (FirebaseApp.getApps(this).isNotEmpty()) return
        FirebaseApp.initializeApp(
            this,
            FirebaseOptions.Builder()
                .setProjectId(BuildConfig.FIREBASE_PROJECT_ID)
                .setApplicationId(BuildConfig.FIREBASE_APPLICATION_ID)
                .setApiKey(BuildConfig.FIREBASE_API_KEY)
                .setGcmSenderId(BuildConfig.FIREBASE_SENDER_ID)
                .build(),
        )
    }

    private fun createChannels() {
        val manager = getSystemService(NotificationManager::class.java)
        // Kanal tovushini o'rnatilgandan keyin o'zgartirib bo'lmaydi — ovozli eski kanal o'chiriladi, yangisi jim
        // (signalni OfferSound chaladi, aks holda ikki ovoz bir vaqtda), tebranish qoladi
        manager.deleteNotificationChannel("offers")
        manager.createNotificationChannel(
            NotificationChannel(CHANNEL_OFFERS, getString(R.string.channel_offers), NotificationManager.IMPORTANCE_HIGH).apply {
                setSound(null, null)
                enableVibration(true)
            },
        )
        manager.createNotificationChannel(
            NotificationChannel(CHANNEL_SERVICE, getString(R.string.channel_service), NotificationManager.IMPORTANCE_LOW),
        )
        // Operator qo'ng'irog'i: ohang va tebranishni IncomingCallNotifier o'zi boshqaradi (to'xtatish uchun), kanal jim
        manager.createNotificationChannel(
            NotificationChannel(CHANNEL_CALLS, getString(R.string.channel_calls), NotificationManager.IMPORTANCE_HIGH).apply {
                setSound(null, null)
                enableVibration(false)
                lockscreenVisibility = android.app.Notification.VISIBILITY_PUBLIC
            },
        )
        manager.createNotificationChannel(
            NotificationChannel(CHANNEL_MESSAGES, getString(R.string.channel_messages), NotificationManager.IMPORTANCE_HIGH),
        )
    }

    companion object {
        const val CHANNEL_OFFERS = "offers_silent"
        const val CHANNEL_SERVICE = "service"
        const val CHANNEL_MESSAGES = "messages"
        const val CHANNEL_CALLS = "calls"

        lateinit var instance: DtaxiApp
            private set
    }
}
