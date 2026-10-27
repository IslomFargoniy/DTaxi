package uz.dtaxi.client

import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Test
import uz.dtaxi.client.data.ClientOrder
import uz.dtaxi.client.data.ClientState
import uz.dtaxi.client.data.DriverPosition
import uz.dtaxi.client.data.LatLng
import uz.dtaxi.client.data.OrderDriver
import uz.dtaxi.client.data.Place
import uz.dtaxi.client.data.TariffRef

class ClientStateTest {
    private fun order(id: Int, status: String, rating: Int? = null, driverAt: LatLng? = null) = ClientOrder(
        id = id, status = status, pickup = Place("A", 40.0, 71.0), dropoff = null, tariffClass = TariffRef(1, "Standart"),
        comment = null, priceEstimate = 10_000, priceFinal = null,
        driver = driverAt?.let { OrderDriver("Ali", "101", 80, it) }, vehicle = null, canCancel = true, rating = rating, createdAt = "",
    )

    @After fun reset() = ClientState.reset()

    @Test fun completedTripMovesToRatingAndClearsActiveOrder() {
        ClientState.setOrder(order(1, "in_progress"))
        ClientState.setOrder(order(1, "completed"))

        assertNull(ClientState.order.value)
        assertEquals(1, ClientState.toRate.value?.id)
        assertNull(ClientState.finished.value)
    }

    @Test fun cancelledOrderIsShownOnceAsFinished() {
        ClientState.setOrder(order(2, "searching"))
        ClientState.setOrder(order(2, "no_driver_found"))

        assertNull(ClientState.order.value)
        assertEquals("no_driver_found", ClientState.finished.value?.status)
        assertNull(ClientState.toRate.value)
    }

    @Test fun driverPositionStartsFromServerAndIgnoresOtherOrders() {
        ClientState.setOrder(order(3, "assigned", driverAt = LatLng(40.1, 71.1)))
        assertEquals(40.1, ClientState.driver.value!!.lat, 0.0)

        ClientState.moveDriver(DriverPosition(3, 40.2, 71.2, 90))
        ClientState.moveDriver(DriverPosition(99, 0.0, 0.0, null))
        assertEquals(40.2, ClientState.driver.value!!.lat, 0.0)

        // Keyingi yangilanish (holat o'zgardi) jonli joyni eski server nuqtasiga qaytarmaydi
        ClientState.setOrder(order(3, "arrived", driverAt = LatLng(40.1, 71.1)))
        assertEquals(40.2, ClientState.driver.value!!.lat, 0.0)
    }

    @Test fun pollMovesDriverOnlyWhenRealtimeIsDown() {
        ClientState.setOrder(order(4, "assigned", driverAt = LatLng(40.1, 71.1)))
        ClientState.moveDriver(DriverPosition(4, 40.2, 71.2, 90))

        // Reverb ulangan — jonli joy server nuqtasidan aniqroq
        ClientState.setLive(true)
        ClientState.syncDriverFromServer(order(4, "assigned", driverAt = LatLng(40.3, 71.3)))
        assertEquals(40.2, ClientState.driver.value!!.lat, 0.0)

        // Reverb uzildi — belgi so'rov javobi bilan yuradi
        ClientState.setLive(false)
        ClientState.syncDriverFromServer(order(4, "assigned", driverAt = LatLng(40.3, 71.3)))
        assertEquals(40.3, ClientState.driver.value!!.lat, 0.0)
        assertEquals(90, ClientState.driver.value!!.heading)

        // Boshqa buyurtmaning javobi belgini o'zgartirmaydi
        ClientState.syncDriverFromServer(order(5, "assigned", driverAt = LatLng(1.0, 1.0)))
        assertEquals(40.3, ClientState.driver.value!!.lat, 0.0)
    }

    @Test fun orderFinishedInBackgroundIsShownFromHistory() {
        ClientState.showFinished(order(6, "searching"))
        assertNull(ClientState.finished.value)

        ClientState.showFinished(order(6, "cancelled"))
        assertEquals(6, ClientState.finished.value?.id)
    }
}
