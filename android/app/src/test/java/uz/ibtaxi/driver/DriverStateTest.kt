package uz.ibtaxi.driver

import org.junit.Assert.assertEquals
import org.junit.Test
import uz.ibtaxi.driver.data.LocationPoint
import uz.ibtaxi.driver.service.LocationBuffer
import uz.ibtaxi.driver.taximeter.ServiceArea
import uz.ibtaxi.driver.taximeter.Taximeter

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
}
