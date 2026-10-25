package uz.dtaxi.driver

import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Test
import uz.dtaxi.driver.data.IncomingCall
import uz.dtaxi.driver.push.PushPayload

class IncomingCallTest {
    @Test fun pushIsParsedWithTheTimeLeft() {
        val call = PushPayload.incomingCall(
            mapOf("type" to "incoming_call", "call_id" to "42", "operator" to "Dilnoza", "expires_in" to "30", "sent_at" to "1000"),
            nowSeconds = 1004,
        )
        assertEquals(IncomingCall(42, "Dilnoza", 26), call)
        assertEquals("answer-42", call?.target)
    }

    @Test fun pushWithoutCallIdIsIgnored() {
        assertNull(PushPayload.incomingCall(mapOf("type" to "incoming_call", "operator" to "Dilnoza"), 0))
    }

    @Test fun wrongPhoneClockKeepsTheFullRingTime() {
        assertEquals(30, IncomingCall.remaining(30, 1000, 900))
        assertEquals(30, IncomingCall.remaining(30, 1000, 5000))
        assertEquals(30, IncomingCall.remaining(30, null, 5000))
        assertEquals(0, IncomingCall.remaining(30, 1000, 1030))
    }
}
