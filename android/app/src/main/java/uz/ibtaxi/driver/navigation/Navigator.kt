package uz.ibtaxi.driver.navigation

import uz.ibtaxi.driver.data.NavStep
import uz.ibtaxi.driver.taximeter.Geo

/**
 * Qadam-baqadam navigatsiya (Bosqich 5): OSRM manevrlari va marshrut chizig'i bo'yicha keyingi burilishni
 * kuzatadi va qachon ovozli ko'rsatma berishni hal qiladi. Joylashuv marshrut chizig'iga proyeksiya qilinadi —
 * GPS nuqtalari siyrak bo'lsa ham (har 4 s) manevrlar o'tkazib yuborilmaydi. Android'ga bog'liq emas — unit testlanadi.
 *
 * @param route marshrut chizig'i [lng, lat] nuqtalari
 */
class Navigator(private val steps: List<NavStep>, private val route: List<List<Double>>) {
    /** Har manevrga eng yaqin chiziq nuqtasi */
    private val stepVertex = steps.map { s -> nearestVertex(s.lat, s.lng) }

    /** Chiziq boshidan har nuqtagacha masofa */
    private val along: List<Double> = buildList {
        var total = 0.0
        add(0.0)
        for (i in 1 until route.size) {
            total += Geo.distance(route[i - 1][1], route[i - 1][0], route[i][1], route[i][0])
            add(total)
        }
    }

    private var vertex = 0

    /** Chiziq boshidan joriy proyeksiyagacha masofa, m */
    private var progress = 0.0
    private var announcedFar = -1
    private var announcedNear = -1
    private var offRouteFixes = 0

    /** Keyingi aytiladigan manevr indeksi */
    var upcomingIndex = firstAnnouncedAfter(0.0)
        private set

    val upcoming: NavStep? get() = steps.getOrNull(upcomingIndex)

    sealed interface Event {
        /** Uzoqdan ogohlantirish: "300 metrdan keyin chapga" */
        data class Prepare(val step: NavStep, val meters: Int) : Event

        /** Hozir: "Chapga buriling" */
        data class Now(val step: NavStep) : Event

        /** Yo'nalishdan chiqildi — marshrutni qayta hisoblash kerak */
        data object OffRoute : Event
    }

    /**
     * Yangi GPS nuqtasi → aytilishi kerak bo'lgan hodisa (yoki null) va manevrgacha yo'l bo'ylab masofa.
     */
    fun update(lat: Double, lng: Double): Pair<Event?, Int?> {
        if (route.isEmpty()) return null to null

        // Orqaga sakramaslik uchun qidiruv joriy segmentdan biroz oldin boshlanadi
        val (segment, offset, along0) = project(lat, lng, from = maxOf(0, vertex - 3))

        if (offset > OFF_ROUTE_METERS) {
            if (++offRouteFixes >= OFF_ROUTE_FIXES) {
                offRouteFixes = 0
                return Event.OffRoute to null
            }
            return null to distanceToUpcoming()
        }
        offRouteFixes = 0
        if (along0 >= progress) {
            progress = along0
            vertex = segment
        }
        upcomingIndex = firstAnnouncedAfter(progress)

        val step = upcoming ?: return null to null
        val meters = distanceToUpcoming()

        val event = when {
            meters <= NEAR_METERS && announcedNear != upcomingIndex -> {
                announcedNear = upcomingIndex
                announcedFar = upcomingIndex
                Event.Now(step)
            }
            meters in (NEAR_METERS * 2)..FAR_METERS && announcedFar != upcomingIndex -> {
                announcedFar = upcomingIndex
                Event.Prepare(step, roundMeters(meters))
            }
            else -> null
        }

        return event to meters
    }

    /** Joriy nuqtadan keyingi manevrgacha yo'l bo'ylab masofa */
    fun distanceToUpcoming(): Int {
        val target = stepVertex.getOrNull(upcomingIndex) ?: return 0
        return maxOf(0.0, along[target] - progress).toInt()
    }

    /** Manevr nuqtasidan PASSED metr o'tilgach u "o'tilgan" hisoblanadi */
    private fun firstAnnouncedAfter(position: Double): Int {
        val index = steps.indices.firstOrNull { i -> along[stepVertex[i]] > position + PASSED_METERS && isAnnounced(steps[i]) }
        return index ?: steps.lastIndex
    }

    /**
     * Nuqtani eng yaqin segmentga proyeksiya: (segment boshi indeksi, chiziqdan uzoqlik m, chiziq bo'ylab masofa m).
     * Kichik masofalarda tekis (ekvirektangulyar) yaqinlashuv yetarli.
     */
    private fun project(lat: Double, lng: Double, from: Int): Triple<Int, Double, Double> {
        if (route.size < 2) return Triple(0, Geo.distance(lat, lng, route[0][1], route[0][0]), 0.0)
        val kx = 111_320.0 * Math.cos(Math.toRadians(lat))
        val ky = 110_540.0
        var best = Triple(from, Double.MAX_VALUE, 0.0)
        for (i in from until route.lastIndex) {
            val ax = route[i][0] * kx
            val ay = route[i][1] * ky
            val bx = route[i + 1][0] * kx
            val by = route[i + 1][1] * ky
            val px = lng * kx
            val py = lat * ky
            val len2 = (bx - ax) * (bx - ax) + (by - ay) * (by - ay)
            val t = if (len2 == 0.0) 0.0 else (((px - ax) * (bx - ax) + (py - ay) * (by - ay)) / len2).coerceIn(0.0, 1.0)
            val qx = ax + t * (bx - ax)
            val qy = ay + t * (by - ay)
            val d = Math.hypot(px - qx, py - qy)
            if (d < best.second) best = Triple(i, d, along[i] + t * (along[i + 1] - along[i]))
        }
        return best
    }

    /** "Ko'cha nomi o'zgardi" va "to'g'ri davom eting" aytilmaydi */
    private fun isAnnounced(step: NavStep): Boolean = when (step.type) {
        "new name", "depart", "notification" -> false
        "continue" -> step.modifier != null && step.modifier != "straight"
        else -> true
    }

    private fun nearestVertex(lat: Double, lng: Double, from: Int = 0): Int {
        var best = from.coerceAtMost(maxOf(0, route.lastIndex))
        var bestMeters = Double.MAX_VALUE
        for (i in best until route.size) {
            val d = Geo.distance(lat, lng, route[i][1], route[i][0])
            if (d < bestMeters) {
                bestMeters = d
                best = i
            }
        }
        return best
    }

    private fun roundMeters(meters: Int): Int = when {
        meters >= 250 -> 300
        meters >= 150 -> 200
        else -> 100
    }

    companion object {
        const val PASSED_METERS = 15.0
        const val NEAR_METERS = 60
        const val FAR_METERS = 320
        const val OFF_ROUTE_METERS = 70.0
        const val OFF_ROUTE_FIXES = 3
    }
}
