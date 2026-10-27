package uz.dtaxi.driver.data

import android.os.SystemClock
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import uz.dtaxi.driver.service.OfferSound

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

    private val _motion = MutableStateFlow(Motion(null, 0))
    /** Navigator uchun: harakat yo'nalishi (gradus, turganda — oxirgisi) va tezlik (km/soat) */
    val motion: StateFlow<Motion> = _motion.asStateFlow()

    private val _meters = MutableStateFlow(0L to 0L)
    /** Taksometr: (shahar ichi, tashqarisi) metr */
    val meters: StateFlow<Pair<Long, Long>> = _meters.asStateFlow()

    private val _unread = MutableStateFlow(0)
    val unread: StateFlow<Int> = _unread.asStateFlow()

    /** Dispetcher shu id gacha bo'lgan xabarlarimizni o'qidi (ChatMessagesRead) — chatda ✓ → ✓✓ */
    private val _chatReadUpTo = MutableStateFlow(0)
    val chatReadUpTo: StateFlow<Int> = _chatReadUpTo.asStateFlow()

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

    /** Qabul so'rovi ketayotgan taklif (offer_id) — tugma o'chiq; Activity'da emas, so'rov ekran qayta yaratilishidan omon qoladi */
    private val _accepting = MutableStateFlow<Int?>(null)
    val accepting: StateFlow<Int?> = _accepting.asStateFlow()

    fun setAccepting(offerId: Int?) = _accepting.update { offerId }

    /** Takrorni e'tiborsiz qoldirish: Reverb va FCM bir xil taklifni yuboradi */
    private val seenOffers = LinkedHashSet<String>()

    fun setProfile(profile: DriverProfile?) = _profile.update { profile }

    fun setStatus(status: String) = _profile.update { it?.copy(status = status) }

    /** Til almashganda xarita nomlari ham shu tilda (server bergan uslub manzili) */
    fun setMapStyle(url: String) = _profile.update { it?.copy(config = it.config.copy(mapStyle = url)) }

    private val _themeMode = MutableStateFlow("system")
    /** system / light / dark — Sozlamalarda tanlanadi */
    val themeMode: StateFlow<String> = _themeMode.asStateFlow()

    fun setThemeMode(mode: String) = _themeMode.update { mode }

    fun setBalance(balance: Long) = _profile.update { it?.copy(balance = balance) }

    private val orderLock = Any()

    /** Har qo'llangan buyurtma holatida oshadi — so'rov ketayotganda kelgan yangi holatni eski javob bosmasin */
    @Volatile var orderVersion = 0L
        private set

    /** Oxirgi yakunlangan buyurtma (id, updated_at) — kech kelgan "in_progress" uni qayta ochmasin */
    private var lastFinished: Pair<Int, Long?>? = null

    /**
     * Reverb (Pusher oqimi) va HTTP javoblari (asosiy oqim) bir vaqtda yozadi.
     * @param ifVersion berilsa — so'rov boshlangandan beri holat o'zgarmagan bo'lsagina qo'llanadi
     * @return false — eski holat, e'tiborsiz qoldirildi
     */
    fun setOrder(order: Order?, ifVersion: Long? = null): Boolean = synchronized(orderLock) {
        if (ifVersion != null && ifVersion != orderVersion) return false
        if (order != null && isStale(order)) return false
        // Safar yakunlandi — "To'lanadi" oynasi (Reverb xabari HTTP javobidan oldin kelishi mumkin)
        if (order?.status == "completed" && order.priceFinal != null && _order.value?.id == order.id) {
            _receipt.update { order.priceFinal }
        }
        if (order != null && order.status in FINISHED) lastFinished = order.id to order.updatedAtMillis
        _order.update { if (order != null && order.status in FINISHED) null else order }
        orderVersion++
        true
    }

    private fun isStale(order: Order): Boolean {
        val incoming = order.updatedAtMillis
        val current = _order.value
        if (current != null && current.id == order.id) {
            val known = current.updatedAtMillis
            if (incoming != null && known != null) {
                if (incoming < known) return true
                // updated_at soniyagacha — bir soniyada ikki o'zgarish bo'lsa holat orqaga qaytmasin
                if (incoming == known && statusRank(order.status) < statusRank(current.status)) return true
            }
        }
        val finished = lastFinished
        if (finished != null && finished.first == order.id && order.status !in FINISHED) {
            // Qayta tayinlangan (yangiroq) buyurtma o'tadi; vaqt noma'lum bo'lsa — yopilgan buyurtma ochilmaydi
            val at = finished.second
            if (incoming == null || at == null || incoming <= at) return true
        }
        return false
    }

    private fun statusRank(status: String) = when (status) {
        "assigned" -> 0
        "arrived" -> 1
        "in_progress" -> 2
        else -> if (status in FINISHED) 3 else -1
    }

    /**
     * [now] — SystemClock.elapsedRealtime, [wallNow] — telefon soati, [clockOffset] — server soati farqi
     * (testlarda qo'lda beriladi). FCM kechikib kelsa qolgan vaqt server yuborgan paytdan sanaladi; muddati
     * o'tgan taklif ko'rsatilmaydi.
     */
    fun offer(
        offer: Offer,
        now: Long = SystemClock.elapsedRealtime(),
        wallNow: Long = System.currentTimeMillis(),
        clockOffset: Long? = ServerClock.offsetMs,
    ): Boolean {
        // Reverb (OkHttp oqimi) va FCM (o'z oqimi) bir vaqtda yozadi
        synchronized(seenOffers) {
            if (!seenOffers.add(offer.dedupKey)) return false
            if (seenOffers.size > 200) seenOffers.remove(seenOffers.first())
        }
        if (_order.value != null) return false
        val elapsed = offer.elapsedMillis(wallNow, clockOffset)
        if (elapsed >= offer.expiresIn * 1000L) return false
        _offer.update { offer.copy(receivedAt = now - elapsed) }
        return true
    }

    /** Muddati o'tdi — faqat aynan shu taklif (o'rniga kelgan yangisi yoki qayta yuborilgani qoladi) */
    fun expireOffer(offer: Offer) = _offer.update {
        if (it == offer) {
            OfferSound.stop()
            null
        } else {
            it
        }
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

    fun setMotion(bearing: Float?, speedKmh: Int) = _motion.update { Motion(bearing ?: it.bearing, speedKmh) }

    fun setMeters(inside: Long, outside: Long) = _meters.update { inside to outside }

    fun setUnread(count: Int) = _unread.update { count }

    fun incrementUnread() = _unread.update { it + 1 }

    fun chatRead(upToId: Int) = _chatReadUpTo.update { maxOf(it, upToId) }

    fun toast(message: String?) = _toast.update { message }

    fun receipt(total: Long?) = _receipt.update { total }

    fun setRealtimeConnected(connected: Boolean) = _realtimeConnected.update { connected }

    fun setPendingComplete(orderId: Int?) = _pendingComplete.update { orderId }

    val FINISHED = setOf("completed", "cancelled_by_client", "cancelled_by_operator", "cancelled_by_driver", "no_driver_found")
}

data class Motion(val bearing: Float?, val speedKmh: Int)
