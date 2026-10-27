package uz.dtaxi.client.data

import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update

/** Haydovchi joylashuvi (ClientDriverMoved) */
data class DriverPosition(val orderId: Int, val lat: Double, val lng: Double, val heading: Int?)

/** Ilova holati: ekranlar va Reverb hodisalari bir manbadan */
object ClientState {
    private val _profile = MutableStateFlow<Profile?>(null)
    val profile: StateFlow<Profile?> = _profile.asStateFlow()

    /** Faol buyurtma (yakunlangan yoki bekor qilingan — null) */
    private val _order = MutableStateFlow<ClientOrder?>(null)
    val order: StateFlow<ClientOrder?> = _order.asStateFlow()

    /** Baholanmagan yakunlangan safar */
    private val _toRate = MutableStateFlow<ClientOrder?>(null)
    val toRate: StateFlow<ClientOrder?> = _toRate.asStateFlow()

    /** Oxirgi yakunlangan holat (bekor qilindi, mashina topilmadi) — bosh ekranda bir marta ko'rsatiladi */
    private val _finished = MutableStateFlow<ClientOrder?>(null)
    val finished: StateFlow<ClientOrder?> = _finished.asStateFlow()

    private val _driver = MutableStateFlow<DriverPosition?>(null)
    val driver: StateFlow<DriverPosition?> = _driver.asStateFlow()

    /** Reverb ulanganmi — uzilgan paytda haydovchi joyi so'rov (polling) javobidan olinadi */
    private val _live = MutableStateFlow(false)
    val live: StateFlow<Boolean> = _live.asStateFlow()

    private val _themeMode = MutableStateFlow("system")
    val themeMode: StateFlow<String> = _themeMode.asStateFlow()

    fun setProfile(profile: Profile?) = _profile.update { profile }

    fun setOrder(order: ClientOrder?) {
        val previous = _order.value
        if (order == null || order.isActive) {
            _order.update { order }
        } else {
            _order.update { null }
            if (previous?.id == order.id) {
                if (order.status == "completed" && order.rating == null) _toRate.update { order } else _finished.update { order }
            }
        }
        // Haydovchi joylashuvi faqat shu buyurtmaniki — server bergan oxirgi joy bilan boshlanadi
        val active = _order.value
        _driver.update { current ->
            when {
                active?.driver?.location == null -> null
                current?.orderId == active.id -> current
                else -> DriverPosition(active.id, active.driver.location.lat, active.driver.location.lng, null)
            }
        }
    }

    fun setToRate(order: ClientOrder?) = _toRate.update { order }

    /** Ilova fonda bo'lganda yakunlangan buyurtma (tarixdan topilgan) — bir marta ko'rsatiladi */
    fun showFinished(order: ClientOrder) {
        if (!order.isActive) _finished.update { order }
    }

    fun clearFinished() = _finished.update { null }

    fun setLive(connected: Boolean) = _live.update { connected }

    /**
     * Reverb ishlamayotganda: so'rov javobidagi haydovchi joyi (server oxirgi GPS nuqtasi) — belgi joyida qotib qolmasin.
     * Reverb ulangan bo'lsa jonli joy aniqroq, server nuqtasi uni eskisiga qaytarmaydi.
     */
    fun syncDriverFromServer(order: ClientOrder?) {
        if (_live.value || order == null || _order.value?.id != order.id) return
        val location = order.driver?.location ?: return
        _driver.update { DriverPosition(order.id, location.lat, location.lng, it?.takeIf { p -> p.orderId == order.id }?.heading) }
    }

    fun moveDriver(position: DriverPosition) {
        if (_order.value?.id == position.orderId) _driver.update { position }
    }

    fun setThemeMode(mode: String) = _themeMode.update { mode }

    fun reset() {
        _profile.update { null }
        _order.update { null }
        _toRate.update { null }
        _finished.update { null }
        _driver.update { null }
        _live.update { false }
    }
}
