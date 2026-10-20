package uz.ibtaxi.driver.data

import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import uz.ibtaxi.driver.service.OfferSound

/**
 * Ilova holati — servis (fon) va ekranlar bitta manbadan o'qiydi.
 */
object DriverState {
    private val _profile = MutableStateFlow<DriverProfile?>(null)
    val profile: StateFlow<DriverProfile?> = _profile.asStateFlow()

    private val _order = MutableStateFlow<Order?>(null)
    val order: StateFlow<Order?> = _order.asStateFlow()

    private val _offer = MutableStateFlow<Offer?>(null)
    val offer: StateFlow<Offer?> = _offer.asStateFlow()

    private val _location = MutableStateFlow<Pair<Double, Double>?>(null)
    val location: StateFlow<Pair<Double, Double>?> = _location.asStateFlow()

    private val _meters = MutableStateFlow(0L to 0L)
    /** Taksometr: (shahar ichi, tashqarisi) metr */
    val meters: StateFlow<Pair<Long, Long>> = _meters.asStateFlow()

    private val _unread = MutableStateFlow(0)
    val unread: StateFlow<Int> = _unread.asStateFlow()

    /** Yakunlangan safar summasi — "To'lanadi" oynasi (buyurtma kartochkasi yopilgandan keyin ham ko'rinadi) */
    private val _receipt = MutableStateFlow<Long?>(null)
    val receipt: StateFlow<Long?> = _receipt.asStateFlow()

    private val _toast = MutableStateFlow<String?>(null)
    val toast: StateFlow<String?> = _toast.asStateFlow()

    /** Reverb ulanishi (liniyada uzilsa — "buyurtmalar kelmayapti" banneri) */
    private val _realtimeConnected = MutableStateFlow(true)
    val realtimeConnected: StateFlow<Boolean> = _realtimeConnected.asStateFlow()

    /** Yakunlash yuborilmoqda (internet yo'q) — buyurtma id */
    private val _pendingComplete = MutableStateFlow<Int?>(null)
    val pendingComplete: StateFlow<Int?> = _pendingComplete.asStateFlow()

    /** Takrorni e'tiborsiz qoldirish: Reverb va FCM bir xil taklifni yuboradi */
    private val seenOffers = LinkedHashSet<Int>()

    fun setProfile(profile: DriverProfile?) = _profile.update { profile }

    fun setStatus(status: String) = _profile.update { it?.copy(status = status) }

    fun setBalance(balance: Long) = _profile.update { it?.copy(balance = balance) }

    fun setOrder(order: Order?) {
        // Safar yakunlandi — "To'lanadi" oynasi (Reverb xabari HTTP javobidan oldin kelishi mumkin)
        if (order?.status == "completed" && order.priceFinal != null && _order.value?.id == order.id) {
            _receipt.update { order.priceFinal }
        }
        _order.update { if (order != null && order.status in FINISHED) null else order }
    }

    fun offer(offer: Offer): Boolean {
        if (!seenOffers.add(offer.offerId)) return false
        if (seenOffers.size > 200) seenOffers.remove(seenOffers.first())
        if (_order.value != null) return false
        _offer.update { offer }
        return true
    }

    fun clearOffer(orderId: Int? = null) = _offer.update {
        if (orderId == null || it?.orderId == orderId) {
            // Qabul, rad, muddati o'tdi yoki boshqasi oldi — signal to'xtaydi
            OfferSound.stop()
            null
        } else {
            it
        }
    }

    fun setLocation(lat: Double, lng: Double) = _location.update { lat to lng }

    fun setMeters(inside: Long, outside: Long) = _meters.update { inside to outside }

    fun setUnread(count: Int) = _unread.update { count }

    fun incrementUnread() = _unread.update { it + 1 }

    fun toast(message: String?) = _toast.update { message }

    fun receipt(total: Long?) = _receipt.update { total }

    fun setRealtimeConnected(connected: Boolean) = _realtimeConnected.update { connected }

    fun setPendingComplete(orderId: Int?) = _pendingComplete.update { orderId }

    val FINISHED = setOf("completed", "cancelled_by_client", "cancelled_by_operator", "cancelled_by_driver", "no_driver_found")
}
