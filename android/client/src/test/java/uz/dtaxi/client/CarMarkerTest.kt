package uz.dtaxi.client

import org.junit.Assert.assertEquals
import org.junit.Test
import uz.dtaxi.client.data.LatLng
import uz.dtaxi.client.ui.CarMarker

class CarMarkerTest {
    @Test
    fun bearingPointsAlongTheMovement() {
        val from = LatLng(40.38, 71.78)
        assertEquals(0f, CarMarker.bearing(from, LatLng(40.39, 71.78)), 0.5f)
        assertEquals(90f, CarMarker.bearing(from, LatLng(40.38, 71.79)), 0.5f)
        assertEquals(180f, CarMarker.bearing(from, LatLng(40.37, 71.78)), 0.5f)
        assertEquals(270f, CarMarker.bearing(from, LatLng(40.38, 71.77)), 0.5f)
    }

    @Test
    fun rotationTakesTheShortWay() {
        // 350° → 10°: 360° orqali 20°, teskari 340° emas
        assertEquals(0f, CarMarker.lerpAngle(350f, 10f, 0.5f), 0.01f)
        assertEquals(10f, CarMarker.lerpAngle(350f, 10f, 1f), 0.01f)
        assertEquals(135f, CarMarker.lerpAngle(90f, 180f, 0.5f), 0.01f)
    }

    @Test
    fun smallMovesAreMeasuredInMeters() {
        assertEquals(111.3, CarMarker.distanceMeters(LatLng(40.0, 71.0), LatLng(40.001, 71.0)), 0.5)
    }
}
