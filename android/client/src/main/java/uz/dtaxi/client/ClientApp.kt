package uz.dtaxi.client

import android.app.Application
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
            if (connected) scope.launch { repository.refreshCurrent() }
        }.also { it.connect() }
    }

    fun stopRealtime() {
        realtime?.disconnect()
        realtime = null
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

        private val EVENTS = listOf("ClientOrderUpdated", "ClientDriverMoved")
    }
}
