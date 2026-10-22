package uz.dtaxi.driver.taximeter

import com.google.gson.JsonArray
import com.google.gson.JsonElement
import com.google.gson.JsonObject
import kotlin.math.asin
import kotlin.math.cos
import kotlin.math.min
import kotlin.math.sin
import kotlin.math.sqrt

object Geo {
    /** Ikki nuqta orasidagi masofa, metr (serverdagi Router::haversine bilan bir xil) */
    fun distance(lat1: Double, lng1: Double, lat2: Double, lng2: Double): Double {
        val r = 6371000.0
        val dLat = Math.toRadians(lat2 - lat1)
        val dLng = Math.toRadians(lng2 - lng1)
        val h = sin(dLat / 2) * sin(dLat / 2) +
            cos(Math.toRadians(lat1)) * cos(Math.toRadians(lat2)) * sin(dLng / 2) * sin(dLng / 2)
        return 2 * r * asin(min(1.0, sqrt(h)))
    }
}

/**
 * Filial xizmat hududi (GeoJSON Polygon/MultiPolygon) — nuqta shahar ichidami (qaror #33).
 * Hudud kelmagan bo'lsa hamma joy "ichkari" hisoblanadi.
 */
class ServiceArea(geoJson: JsonElement?) {
    /** Har poligon: halqalar ro'yxati, halqa — [lng, lat] juftliklari. Hudud yo'q (null/JsonNull) — hamma joy shahar ichi */
    private val polygons: List<List<List<DoubleArray>>> = parse(geoJson?.takeIf { it.isJsonObject }?.asJsonObject)

    fun contains(lat: Double, lng: Double): Boolean {
        if (polygons.isEmpty()) return true
        return polygons.any { rings ->
            rings.isNotEmpty() && inRing(rings[0], lat, lng) && rings.drop(1).none { inRing(it, lat, lng) }
        }
    }

    private fun inRing(ring: List<DoubleArray>, lat: Double, lng: Double): Boolean {
        var inside = false
        var j = ring.size - 1
        for (i in ring.indices) {
            val (xi, yi) = ring[i].let { it[0] to it[1] }
            val (xj, yj) = ring[j].let { it[0] to it[1] }
            if ((yi > lat) != (yj > lat) && lng < (xj - xi) * (lat - yi) / (yj - yi) + xi) {
                inside = !inside
            }
            j = i
        }
        return inside
    }

    private fun parse(geoJson: JsonObject?): List<List<List<DoubleArray>>> {
        if (geoJson == null || !geoJson.has("coordinates")) return emptyList()
        val coordinates = geoJson.getAsJsonArray("coordinates")
        fun ring(array: JsonArray) = array.map { p -> p.asJsonArray.let { doubleArrayOf(it[0].asDouble, it[1].asDouble) } }
        fun polygon(array: JsonArray) = array.map { ring(it.asJsonArray) }
        return when (geoJson.get("type")?.asString) {
            "Polygon" -> listOf(polygon(coordinates))
            "MultiPolygon" -> coordinates.map { polygon(it.asJsonArray) }
            else -> emptyList()
        }
    }
}
