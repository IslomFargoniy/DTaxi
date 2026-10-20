package uz.ibtaxi.driver

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test
import uz.ibtaxi.driver.data.Tariff
import uz.ibtaxi.driver.taximeter.Fare
import uz.ibtaxi.driver.taximeter.ServiceArea
import uz.ibtaxi.driver.taximeter.Taximeter
import com.google.gson.JsonParser
import java.time.LocalTime

/** Server testlari (tests/Unit/FareCalculatorTest.php) bilan bir xil holatlar */
class FareTest {
    private fun tariff(
        waiting: Boolean = false,
        night: Boolean = false,
        outside: Boolean = false,
        step: Long = 500,
    ) = Tariff(1, "Standart", 10000, "3.00", 2000, step, waiting, 3, 500, night, "23:00:00", "06:00:00", "1.20", outside, 3000)

    @Test fun shortTripIsBaseFare() = assertEquals(10000, Fare.calculate(tariff(), 2500).total)

    @Test fun planExample() {
        val fare = Fare.calculate(tariff(), 7400)
        assertEquals(19000, fare.total)
        assertEquals(8800, fare.distance)
    }

    @Test fun roundingSteps() {
        assertEquals(19000, Fare.calculate(tariff(step = 1000), 7400).total)
        assertEquals(18800, Fare.calculate(tariff(step = 100), 7400).total)
    }

    @Test fun waitingAfterFreeMinutes() {
        assertEquals(10000, Fare.calculate(tariff(), 2000, waitingSeconds = 600).total)
        assertEquals(11500, Fare.calculate(tariff(waiting = true), 2000, waitingSeconds = 5 * 60 + 10).total)
    }

    @Test fun nightAcrossMidnight() {
        listOf("23:30" to 12000L, "02:00" to 12000L, "05:59" to 12000L, "06:00" to 10000L, "12:00" to 10000L).forEach { (time, expected) ->
            assertEquals(time, expected, Fare.calculate(tariff(night = true), 2000, startedAt = LocalTime.parse(time)).total)
        }
    }

    @Test fun outsideKilometres() {
        assertEquals(16000, Fare.calculate(tariff(), 4000, 2000).total)
        assertEquals(18000, Fare.calculate(tariff(outside = true), 4000, 2000).total)
    }

    // Server: tests/Feature/Orders/PricingRulesTest.php
    @Test fun surgeCoefficient() {
        val fare = Fare.calculate(tariff(), 2000, surgeCoeff = 1.5)
        assertEquals(15000, fare.total)
        assertEquals(5000, fare.surge)
    }

    @Test fun clientDiscount() {
        val fare = Fare.calculate(tariff(), 2000, discountPercent = 10)
        assertEquals(9000, fare.total)
        assertEquals(-1000, fare.discount)
        assertEquals(8000, Fare.calculate(tariff(), 2000, discountPercent = 20).total)
    }

    @Test fun bonusIsAddedOnTop() {
        // Ustama formula ichida emas — yakuniy narxga qo'shiladi (OrderService::complete)
        assertEquals(15000, Fare.calculate(tariff(), 2000).total + 5000)
    }

    @Test fun allRulesTogether() {
        val fare = Fare.calculate(tariff(waiting = true, night = true, outside = true), 5000, 1000, 4 * 60, LocalTime.parse("23:15"))
        assertEquals(21000, fare.total)
        assertEquals(3500, fare.night)
    }

    @Test fun taximeterFiltersJumpsAndSplitsInsideOutside() {
        val area = ServiceArea(JsonParser.parseString("""{"type":"Polygon","coordinates":[[[71.0,40.0],[72.0,40.0],[72.0,41.0],[71.0,41.0],[71.0,40.0]]]}""").asJsonObject)
        assertTrue(area.contains(40.5, 71.5))
        assertFalse(area.contains(40.5, 72.5))

        val meter = Taximeter(area)
        var t = 0L
        meter.add(40.5, 71.5, 5f, t)
        // ~111 m shimolga, 10 s ichida — hisoblanadi
        t += 10_000; assertTrue(meter.add(40.501, 71.5, 5f, t))
        // aniqlik past — tashlanadi
        t += 10_000; assertFalse(meter.add(40.502, 71.5, 80f, t))
        // 1 soniyada 11 km — sakrash
        t += 1_000; assertFalse(meter.add(40.6, 71.5, 5f, t))
        assertEquals(111.0, meter.insideMeters, 2.0)
        assertEquals(0.0, meter.outsideMeters, 0.1)
    }
}
