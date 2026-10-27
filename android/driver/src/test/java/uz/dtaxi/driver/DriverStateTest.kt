package uz.dtaxi.driver

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import uz.dtaxi.driver.data.DriverState
import uz.dtaxi.driver.data.Order
import uz.dtaxi.driver.data.Place
import org.junit.Test
import uz.dtaxi.driver.data.LocationPoint
import uz.dtaxi.driver.service.LocationBuffer
import uz.dtaxi.driver.taximeter.ServiceArea
import uz.dtaxi.driver.taximeter.Taximeter

class DriverStateTest {
    @Test fun taximeterContinuesAfterRestore() {
        val start = 1_000_000L
        val first = Taximeter(ServiceArea(null))
        first.add(40.3800, 71.7800, 5f, start)
        first.add(40.3810, 71.7800, 5f, start + 10_000)
        val saved = first.snapshot(42)

        // Jarayon o'ldi — yangi taksometr saqlangan holatdan davom etadi
        val restored = Taximeter(ServiceArea(null)).also { it.restore(saved) }
        restored.add(40.3820, 71.7800, 5f, start + 20_000)

        assertEquals(42, saved.orderId)
        assertEquals(first.insideMeters * 2, restored.insideMeters, 1.0)
    }

    @Test fun bufferIsCappedByCountAndAge() {
        val buffer = LocationBuffer(maxPoints = 5, maxAgeMillis = 60_000)
        repeat(10) { buffer.add(LocationPoint(40.0, 71.0, 0, 0, 5, t = 1_000L + it)) }
        assertEquals(5, buffer.size)
        assertEquals(1_005L, buffer.batch(1).first().t)

        buffer.add(LocationPoint(40.0, 71.0, 0, 0, 5, t = 200_000L))
        assertEquals(1, buffer.size)
    }

    @Test fun bufferSendsOldestFirstInBatches() {
        val buffer = LocationBuffer()
        repeat(450) { buffer.add(LocationPoint(40.0, 71.0, 0, 0, 5, t = 1_000L + it)) }
        val batch = buffer.batch(200)
        assertEquals(200, batch.size)
        assertEquals(1_000L, batch.first().t)
        buffer.drop(batch.size)
        assertEquals(250, buffer.size)
        assertEquals(1_200L, buffer.batch(1).first().t)
    }

    @Test fun standingStillDoesNotAddDistance() {
        val meter = Taximeter(ServiceArea(null))
        var t = 1_000_000L
        meter.add(40.3800, 71.7800, 15f, t)
        // Turibdi: nuqtalar 10-20 m "sakraydi", tezlik 0
        listOf(0.0001, -0.0001, 0.00015, -0.00005).forEach { d ->
            t += 1_000
            assertFalse(meter.add(40.3800 + d, 71.7800, 15f, t, speedMps = 0.2f))
        }
        // Tezlik noma'lum — aniqlik doirasidagi siljish ham hisoblanmaydi
        t += 1_000
        assertFalse(meter.add(40.3801, 71.7800, 15f, t, speedMps = null))
        assertEquals(0.0, meter.totalMeters, 0.01)

        // Harakat boshlandi — langardan (birinchi nuqtadan) butun masofa qo'shiladi
        t += 10_000
        assertTrue(meter.add(40.3810, 71.7800, 5f, t, speedMps = 10f))
        assertEquals(111.0, meter.totalMeters, 2.0)
    }

    private fun order(status: String, updatedAt: String?) =
        Order(7, status, null, null, Place("A", 40.0, 71.0), null, null, null, null, null, null, updatedAt = updatedAt)

    @Test fun olderOrderStateDoesNotOverwriteNewer() {
        DriverState.setOrder(null)
        assertTrue(DriverState.setOrder(order("arrived", "2026-10-27T10:00:05+05:00")))
        // Kech kelgan eski "assigned"
        assertFalse(DriverState.setOrder(order("assigned", "2026-10-27T10:00:01+05:00")))
        assertEquals("arrived", DriverState.order.value?.status)
        // Bir soniyada — holat orqaga qaytmaydi, oldinga o'tadi
        assertFalse(DriverState.setOrder(order("assigned", "2026-10-27T10:00:05+05:00")))
        assertTrue(DriverState.setOrder(order("in_progress", "2026-10-27T10:00:05+05:00")))

        // Yakunlandi — kech kelgan "in_progress" buyurtmani qayta ochmaydi
        assertTrue(DriverState.setOrder(order("completed", "2026-10-27T10:20:00+05:00")))
        assertFalse(DriverState.setOrder(order("in_progress", "2026-10-27T10:10:00+05:00")))
        assertNull(DriverState.order.value)

        // So'rov ketayotganda holat o'zgardi — eski javob qo'llanmaydi
        val version = DriverState.orderVersion
        DriverState.setOrder(null)
        assertFalse(DriverState.setOrder(order("assigned", "2026-10-27T11:00:00+05:00"), ifVersion = version))
        DriverState.setOrder(null)
    }
}
