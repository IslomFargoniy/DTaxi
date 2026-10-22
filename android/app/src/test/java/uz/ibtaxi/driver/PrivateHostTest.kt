package uz.ibtaxi.driver

import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test
import uz.ibtaxi.driver.ui.CallActivity

class PrivateHostTest {
    @Test fun localNetworkHostsAreAllowed() {
        listOf("192.168.252.2", "10.0.0.5", "172.16.0.1", "172.31.255.255", "mac.local").forEach {
            assertTrue(it, CallActivity.isPrivateHost(it))
        }
    }

    @Test fun publicHostsAreRejected() {
        listOf("8.8.8.8", "172.32.0.1", "172.15.0.1", "192.169.0.1", "taxi.uz", "192.168.evil.com", "10.0.0.1.evil.com", "", null).forEach {
            assertFalse(it.toString(), CallActivity.isPrivateHost(it))
        }
    }
}
