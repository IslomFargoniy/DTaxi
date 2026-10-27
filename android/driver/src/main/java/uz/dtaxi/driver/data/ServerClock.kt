package uz.dtaxi.driver.data

import kotlin.math.abs

/**
 * Telefon soati serverdan farq qilishi mumkin — farq HTTP javoblarining `Date` sarlavhasidan olinadi.
 * Kechikib kelgan taklifning qolgan vaqti server vaqti (offered_at) bo'yicha sanaladi.
 */
object ServerClock {
    /** server − telefon (ms); null — hali birorta javob kelmagan */
    @Volatile var offsetMs: Long? = null
        private set

    private var persist: ((Long) -> Unit)? = null

    fun init(saved: Long?, persist: (Long) -> Unit) {
        offsetMs = saved
        this.persist = persist
    }

    fun onServerDate(serverMillis: Long, localMillis: Long) {
        val offset = serverMillis - localMillis
        val previous = offsetMs
        offsetMs = offset
        // Date sarlavhasi soniyagacha — har javobda diskka yozilmasin
        if (previous == null || abs(previous - offset) > 2_000) persist?.invoke(offset)
    }
}
