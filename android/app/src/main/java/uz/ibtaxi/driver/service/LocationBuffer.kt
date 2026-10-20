package uz.ibtaxi.driver.service

import uz.ibtaxi.driver.data.LocationPoint

/**
 * Yuborilmagan GPS nuqtalari: internet uzilganda 2 soatgacha saqlanadi (safar treki serverda tekshiriladi),
 * eskisidan boshlab 200 talab yuboriladi.
 */
class LocationBuffer(
    private val maxPoints: Int = 3000,
    private val maxAgeMillis: Long = 2 * 60 * 60 * 1000L,
) {
    private val points = ArrayDeque<LocationPoint>()

    val size: Int get() = points.size

    fun isEmpty() = points.isEmpty()

    fun add(point: LocationPoint) {
        points.addLast(point)
        trim(point.t)
    }

    fun addAll(saved: List<LocationPoint>, now: Long) {
        saved.forEach { points.addLast(it) }
        trim(now)
    }

    fun batch(limit: Int = 200): List<LocationPoint> = points.take(limit)

    /** Server qabul qilgan eng eski [count] nuqtani o'chirish */
    fun drop(count: Int) = repeat(count) { if (points.isNotEmpty()) points.removeFirst() }

    fun toList(): List<LocationPoint> = points.toList()

    private fun trim(now: Long) {
        while (points.size > maxPoints) points.removeFirst()
        while (points.isNotEmpty() && now - points.first().t > maxAgeMillis) points.removeFirst()
    }
}
