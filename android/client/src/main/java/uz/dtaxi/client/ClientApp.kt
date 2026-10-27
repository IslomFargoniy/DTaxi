package uz.dtaxi.client

import android.app.Application
import android.app.NotificationChannel
import android.app.NotificationManager
import com.google.firebase.FirebaseApp
import com.google.firebase.FirebaseOptions
import com.google.firebase.messaging.FirebaseMessaging
import kotlinx.coroutines.tasks.await
import com.google.gson.Gson
import com.google.gson.JsonObject
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.launch
import org.maplibre.android.MapLibre
import uz.dtaxi.client.data.ClientOrder
import uz.dtaxi.client.data.ClientState
import uz.dtaxi.client.data.DriverPosition
import uz.dtaxi.client.data.Repository
import uz.dtaxi.client.data.Session
import uz.dtaxi.core.realtime.Realtime

class ClientApp : Application() {
    val scope = CoroutineScope(SupervisorJob() + Dispatchers.Main)

    lateinit var session: Session
        private set
    lateinit var repository: Repository
        private set

    private var realtime: Realtime? = null
    private val gson = Gson()

    override fun onCreate() {
        super.onCreate()
        instance = this
        session = Session(this)
        repository = Repository(session)
        ClientState.setThemeMode(session.themeMode)
        MapLibre.getInstance(this)
        getSystemService(NotificationManager::class.java).createNotificationChannel(
            NotificationChannel(CHANNEL_ORDERS, getString(R.string.channel_orders), NotificationManager.IMPORTANCE_HIGH),
        )
        initFirebase()
        // Faol buyurtma raqami saqlanadi: fonda/yopiq paytda yakunlansa qaytganda natija ko'rsatiladi (refreshCurrent)
        scope.launch {
            var previous: Int? = null
            ClientState.order.collect { order ->
                if (order != null) {
                    session.activeOrderId = order.id
                } else if (previous != null) {
                    // Yakuni ilova ochiq paytda keldi (Reverb yoki so'rov) — qayta ko'rsatilmasin
                    session.activeOrderId = null
                }
                previous = order?.id
            }
        }
    }

    /** Firebase — firmaning o'z loyihasi (qaror #35, #71); sozlanmagan bo'lsa push o'chiq, holat faqat Reverb orqali */
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

    /** Kirgandan keyin va har ochilishda — token serverga (telefon boshqa mijozga o'tsa token unga o'tadi) */
    fun registerPush() {
        if (FirebaseApp.getApps(this).isEmpty() || !repository.loggedIn) return
        scope.launch {
            runCatching { FirebaseMessaging.getInstance().token.await() }.getOrNull()?.let { repository.registerPushToken(it) }
        }
    }

    /**
     * Ilova ochiq paytida Reverb: buyurtma holati va haydovchi joylashuvi (private-client.{id}).
     * Fonda ulanish yo'q — ochilganda holat serverdan qayta olinadi.
     */
    fun startRealtime() {
        val profile = ClientState.profile.value ?: return
        val token = session.token ?: return
        if (realtime != null) return
        realtime = Realtime(profile.config.reverb, token, BuildConfig.SERVER_URL, "private-client.${profile.id}", EVENTS, ::onEvent) { connected ->
            // Uzilish paytida kelgan xabarlar yo'qolgan — holat serverdan
            scope.launch {
                ClientState.setLive(connected)
                if (connected) repository.refreshCurrent()
            }
        }.also { it.connect() }
    }

    fun stopRealtime() {
        realtime?.disconnect()
        realtime = null
        ClientState.setLive(false)
    }

    /** Pusher oqimida — asosiy oqimga o'tkaziladi */
    private fun onEvent(name: String, data: JsonObject) {
        scope.launch {
            runCatching {
                when (name) {
                    "ClientOrderUpdated" -> ClientState.setOrder(gson.fromJson(data.getAsJsonObject("order"), ClientOrder::class.java))
                    "ClientDriverMoved" -> ClientState.moveDriver(
                        DriverPosition(
                            data.get("order_id").asInt,
                            data.get("lat").asDouble,
                            data.get("lng").asDouble,
                            data.get("heading")?.takeIf { !it.isJsonNull }?.asInt,
                        ),
                    )
                }
            }.onFailure { android.util.Log.w("ClientApp", "Reverb xabari o'qilmadi: $name", it) }
        }
    }

    companion object {
        lateinit var instance: ClientApp
            private set

        const val CHANNEL_ORDERS = "orders"

        private val EVENTS = listOf("ClientOrderUpdated", "ClientDriverMoved")
    }
}
