package uz.ibtaxi.driver.taximeter

/** Taksometr holati — jarayon o'ldirilsa ham davom etishi uchun diskka yoziladi */
data class MeterState(
    val orderId: Int,
    val inside: Double,
    val outside: Double,
    val lastLat: Double?,
    val lastLng: Double?,
    val lastTime: Long,
)

/**
 * GPS bo'yicha bosib o'tilgan masofa (qaror #21, #33). Internet uzilsa ham ishlaydi — hisob telefonda.
 * Sakrashlar filtrlanadi: aniqligi past nuqtalar va imkonsiz tezlik (aks holda masofa sun'iy oshadi).
 */
class Taximeter(private val area: ServiceArea) {
    var insideMeters = 0.0
        private set
    var outsideMeters = 0.0
        private set

    private var lastLat: Double? = null
    private var lastLng: Double? = null
    private var lastTime: Long = 0

    val totalMeters: Double get() = insideMeters + outsideMeters

    fun snapshot(orderId: Int) = MeterState(orderId, insideMeters, outsideMeters, lastLat, lastLng, lastTime)

    fun restore(state: MeterState) {
        insideMeters = state.inside
        outsideMeters = state.outside
        lastLat = state.lastLat
        lastLng = state.lastLng
        lastTime = state.lastTime
    }

    fun reset() {
        insideMeters = 0.0
        outsideMeters = 0.0
        lastLat = null
        lastLng = null
        lastTime = 0
    }

    /**
     * @return true — nuqta hisobga olindi
     */
    fun add(lat: Double, lng: Double, accuracyMeters: Float?, timeMillis: Long): Boolean {
        if (accuracyMeters != null && accuracyMeters > MAX_ACCURACY_M) return false

        val prevLat = lastLat
        val prevLng = lastLng
        if (prevLat == null || prevLng == null) {
            remember(lat, lng, timeMillis)
            return true
        }

        val meters = Geo.distance(prevLat, prevLng, lat, lng)
        val seconds = (timeMillis - lastTime).coerceAtLeast(1) / 1000.0

        // Turganda GPS "titrashi" — hisoblanmaydi
        if (meters < MIN_STEP_M) return false
        // 200 km/soatdan tez — sakrash
        if (meters / seconds > MAX_SPEED_MPS) return false

        if (area.contains(lat, lng)) insideMeters += meters else outsideMeters += meters
        remember(lat, lng, timeMillis)
        return true
    }

    private fun remember(lat: Double, lng: Double, time: Long) {
        lastLat = lat
        lastLng = lng
        lastTime = time
    }

    companion object {
        const val MAX_ACCURACY_M = 35f
        const val MIN_STEP_M = 5.0
        const val MAX_SPEED_MPS = 200 / 3.6
    }
}
