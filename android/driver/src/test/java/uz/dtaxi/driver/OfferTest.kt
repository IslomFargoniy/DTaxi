package uz.dtaxi.driver

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test
import uz.dtaxi.driver.data.DriverState
import uz.dtaxi.driver.push.PushPayload

class OfferTest {
    private fun data(offerId: String, offeredAt: String = "") = mapOf(
        "type" to "order.offered",
        "offer_id" to offerId,
        "order_id" to "77",
        "is_direct" to "",
        "distance_m" to "",
        "pickup_text" to "Mustaqillik 5",
        "pickup_lat" to "40.38",
        "pickup_lng" to "71.78",
        "dropoff_text" to "",
        "tariff_class" to "Start",
        "is_delivery" to "1",
        "price_estimate" to "",
        "comment" to "",
        "expires_in" to "15",
        "price_bonus" to "",
        "is_corporate" to "",
        "stops_text" to "",
        "offered_at" to offeredAt,
    )

    @Test fun pushWithBlankFieldsIsParsed() {
        val offer = PushPayload.offer(data("5", "1700000000123"))
        assertNotNull(offer)
        offer!!
        assertEquals(5, offer.offerId)
        assertEquals(77, offer.orderId)
        assertFalse(offer.isDirect)
        assertTrue(offer.isDelivery)
        assertNull(offer.distanceM)
        assertNull(offer.priceEstimate)
        assertNull(offer.priceBonus)
        assertNull(offer.dropoffText)
        assertNull(offer.comment)
        assertNull(offer.stopsText)
        assertEquals(15, offer.expiresIn)
        assertEquals(1_700_000_000_123L, offer.offeredAt)
        assertEquals("77:5:1700000000123", offer.dedupKey)
    }

    @Test fun pushWithoutOfferedAtFallsBackToOfferId() {
        val offer = PushPayload.offer(data("6") - "offered_at")!!
        assertNull(offer.offeredAt)
        assertEquals("77:6", offer.dedupKey)
        assertNull(PushPayload.offer(mapOf("offer_id" to "")))
    }

    @Test fun reOfferWithNewOfferedAtIsShownAgain() {
        DriverState.setOrder(null)
        val first = PushPayload.offer(data("900", "1000"))!!
        assertTrue(DriverState.offer(first, now = 0))
        // Reverb va FCM bir xil yuborgan — takror
        assertFalse(DriverState.offer(first, now = 0))
        // Server qayta yubordi (yangi offered_at) — yana ko'rinadi
        assertTrue(DriverState.offer(PushPayload.offer(data("900", "2000"))!!, now = 5_000))
        assertEquals(5_000L, DriverState.offer.value?.receivedAt)

        // Eski server: faqat offer_id bo'yicha
        val legacy = PushPayload.offer(data("901"))!!
        assertTrue(DriverState.offer(legacy, now = 0))
        assertFalse(DriverState.offer(legacy, now = 0))
        DriverState.clearOffer()
    }

    @Test fun timerCountsFromReceivedAt() {
        DriverState.setOrder(null)
        assertTrue(DriverState.offer(PushPayload.offer(data("902", "1"))!!, now = 10_000))
        val offer = DriverState.offer.value!!
        assertEquals(15, offer.secondsLeft(10_000))
        assertEquals(10, offer.secondsLeft(15_000))
        assertEquals(0, offer.secondsLeft(25_000))
        assertEquals(0L, offer.millisLeft(40_000))

        DriverState.expireOffer(offer.copy(receivedAt = 1))
        assertNotNull(DriverState.offer.value)
        DriverState.expireOffer(offer)
        assertNull(DriverState.offer.value)
    }

    @Test fun latePushCountsFromOfferedAt() {
        DriverState.setOrder(null)
        DriverState.clearOffer()
        // Server soati telefondan 60 s oldinda; FCM 5 s kechikdi — 15 s dan 10 s qoldi
        val late = PushPayload.offer(data("903", "1000000"))!!
        assertTrue(DriverState.offer(late, now = 50_000, wallNow = 1_000_000 - 60_000 + 5_000, clockOffset = 60_000))
        assertEquals(10, DriverState.offer.value!!.secondsLeft(50_000))
        DriverState.clearOffer()

        // Muddati o'tib kelgan — ko'rsatilmaydi
        val expired = PushPayload.offer(data("904", "1000000"))!!
        assertFalse(DriverState.offer(expired, now = 0, wallNow = 1_020_000, clockOffset = 0))
        assertNull(DriverState.offer.value)

        // Soat farqi noma'lum va telefon soati noto'g'ri — to'liq muddat (taklif yo'qolmasin)
        val skewed = PushPayload.offer(data("905", "1000000"))!!
        assertTrue(DriverState.offer(skewed, now = 0, wallNow = 5_000_000, clockOffset = null))
        assertEquals(15, DriverState.offer.value!!.secondsLeft(0))
        DriverState.clearOffer()
    }
}
