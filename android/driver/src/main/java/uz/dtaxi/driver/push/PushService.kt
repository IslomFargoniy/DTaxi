package uz.dtaxi.driver.push

import com.google.firebase.messaging.FirebaseMessagingService
import com.google.firebase.messaging.RemoteMessage
import kotlinx.coroutines.launch
import uz.dtaxi.driver.DtaxiApp
import uz.dtaxi.driver.data.DriverState
import uz.dtaxi.driver.service.IncomingCallNotifier
import uz.dtaxi.driver.service.OfferNotifier

/**
 * FCM: ilova fonda yoki yopiq bo'lsa uyg'otadi (qaror #1); operator qo'ng'irog'i ham (qaror #59). Reverb bilan takrorlansa offer_id (+ offered_at) bo'yicha tashlanadi.
 */
class PushService : FirebaseMessagingService() {
    override fun onNewToken(token: String) {
        val app = application as DtaxiApp
        if (app.session.token != null) {
            // Ilova scope'i — boshqariladigan, servis yopilsa ham so'rov tugaydi (Retrofit o'zi fon oqimida)
            app.scope.launch { app.repository.registerPushToken(token) }
        }
    }

    override fun onMessageReceived(message: RemoteMessage) {
        val data = message.data
        when (data["type"]) {
            "order.offered" -> {
                val offer = PushPayload.offer(data) ?: return
                // Kechikib kelgan bo'lsa qolgan vaqt offered_at dan; muddati o'tgan bo'lsa ko'rsatilmaydi
                if (DriverState.offer(offer)) OfferNotifier.show(this, DriverState.offer.value ?: offer)
            }
            "incoming_call" -> PushPayload.incomingCall(data, System.currentTimeMillis() / 1000)?.let { IncomingCallNotifier.show(this, it) }
            "incoming_call_ended" -> data["call_id"]?.toIntOrNull()?.let { IncomingCallNotifier.ended(this, it) }
            "chat.message" -> data["body"]?.let { OfferNotifier.message(this, it) }
            "balance.topup" -> data["balance"]?.toLongOrNull()?.let(DriverState::setBalance)
        }
    }
}
