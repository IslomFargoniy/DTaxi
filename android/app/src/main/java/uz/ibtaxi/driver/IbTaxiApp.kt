package uz.ibtaxi.driver

import android.app.Application
import android.app.NotificationChannel
import android.app.NotificationManager
import com.google.firebase.FirebaseApp
import com.google.firebase.FirebaseOptions
import org.maplibre.android.MapLibre
import uz.ibtaxi.driver.data.ApiClient
import uz.ibtaxi.driver.data.Repository
import uz.ibtaxi.driver.data.Session

class IbTaxiApp : Application() {
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
        repository = Repository(ApiClient(session), session)
        uz.ibtaxi.driver.data.DriverState.setThemeMode(session.themeMode)
        MapLibre.getInstance(this)
        initFirebase()
        createChannels()
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
        manager.createNotificationChannel(
            NotificationChannel(CHANNEL_MESSAGES, getString(R.string.channel_messages), NotificationManager.IMPORTANCE_HIGH),
        )
    }

    companion object {
        const val CHANNEL_OFFERS = "offers_silent"
        const val CHANNEL_SERVICE = "service"
        const val CHANNEL_MESSAGES = "messages"

        lateinit var instance: IbTaxiApp
            private set
    }
}
