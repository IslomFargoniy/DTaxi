package uz.dtaxi.driver

import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test
import uz.dtaxi.driver.data.NavStep
import uz.dtaxi.driver.navigation.Instructions
import uz.dtaxi.driver.navigation.Navigator

class NavigatorTest {
    // Shimolga ~500 m to'g'ri yo'l (oraliqda ko'cha nomi o'zgaradi), so'ng chapga ~200 m, manzil
    private val steps = listOf(
        NavStep("depart", null, "", 250, 40.3800, 71.7800),
        NavStep("new name", "straight", "Dodxox", 250, 40.3822, 71.7800),
        NavStep("turn", "left", "Mustaqillik", 200, 40.3845, 71.7800),
        NavStep("arrive", "left", "", 0, 40.3845, 71.7776),
    )

    // Siyrak chiziq: to'g'ri yo'lda faqat ikki nuqta
    private val route = listOf(listOf(71.7800, 40.3800), listOf(71.7800, 40.3822), listOf(71.7800, 40.3845), listOf(71.7776, 40.3845))

    @Test
    fun announcesTurnFarThenNearEvenWithSparseGps() {
        val nav = Navigator(steps, route)
        assertEquals("turn", nav.upcoming?.type)
        assertNull(nav.update(40.3800, 71.7800).first)

        // 300 m sakrash: ko'cha nomi o'zgargan nuqtadan o'tib ketildi
        val far = nav.update(40.3822, 71.7801)
        assertTrue(far.first is Navigator.Event.Prepare)
        assertEquals(300, (far.first as Navigator.Event.Prepare).meters)
        assertTrue(far.second!! in 240..270)
        assertNull(nav.update(40.3825, 71.7800).first) // takrorlanmaydi

        val near = nav.update(40.3841, 71.7800).first // ~45 m
        assertTrue(near is Navigator.Event.Now)
    }

    @Test
    fun movesToArrivalAfterTheTurn() {
        val nav = Navigator(steps, route)
        nav.update(40.3845, 71.7795)
        assertEquals("arrive", nav.upcoming?.type)
    }

    @Test
    fun stayingOnALongStraightSegmentIsNotOffRoute() {
        val nav = Navigator(steps, route)
        repeat(4) { assertTrue(nav.update(40.3810, 71.7801).first !is Navigator.Event.OffRoute) }
    }

    @Test
    fun detectsOffRouteAfterSeveralFixes() {
        val nav = Navigator(steps, route)
        repeat(2) { assertTrue(nav.update(40.3820, 71.7850).first !is Navigator.Event.OffRoute) }
        assertTrue(nav.update(40.3820, 71.7850).first is Navigator.Event.OffRoute)
    }

    @Test
    fun instructionTexts() {
        assertEquals("300 metrdan keyin chapga buriling, Mustaqillik", Instructions.text(steps[2], "uz", 300))
        assertEquals("Через 300 метров поверните налево на Mustaqillik", Instructions.text(steps[2], "ru", 300))
        assertEquals("300 метрдан кейин чапга бурилинг, Mustaqillik", Instructions.text(steps[2], "oz", 300))
        assertEquals("In 300 meters turn left onto Mustaqillik", Instructions.text(steps[2], "en", 300))
        assertEquals("Manzilga yetib keldingiz", Instructions.text(steps[3], "uz"))
    }
}
