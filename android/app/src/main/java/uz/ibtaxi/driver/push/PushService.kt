package uz.ibtaxi.driver.push

import com.google.firebase.messaging.FirebaseMessagingService
import com.google.firebase.messaging.RemoteMessage
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import uz.ibtaxi.driver.IbTaxiApp
import uz.ibtaxi.driver.data.DriverState
import uz.ibtaxi.driver.service.IncomingCallNotifier
import uz.ibtaxi.driver.service.OfferNotifier

/**
 * FCM: ilova fonda yoki yopiq bo'lsa uyg'otadi (qaror #1); operator qo'ng'irog'i ham (qaror #59). Reverb bilan takrorlansa offer_id (+ offered_at) bo'yicha tashlanadi.
 */
class PushService : FirebaseMessagingService() {
    override fun onNewToken(token: String) {
        val app = application as IbTaxiApp
        if (app.session.token != null) {
            CoroutineScope(Dispatchers.IO).launch { app.repository.registerPushToken(token) }
        }
    }

    override fun onMessageReceived(message: RemoteMessage) {
        val data = message.data
        when (data["type"]) {
            "order.offered" -> {
                val offer = PushPayload.offer(data) ?: return
                if (DriverState.offer(offer)) OfferNotifier.show(this, offer)
            }
            "incoming_call" -> PushPayload.incomingCall(data, System.currentTimeMillis() / 1000)?.let { IncomingCallNotifier.show(this, it) }
            "incoming_call_ended" -> data["call_id"]?.toIntOrNull()?.let { IncomingCallNotifier.ended(this, it) }
            "chat.message" -> data["body"]?.let { OfferNotifier.message(this, it) }
            "balance.topup" -> data["balance"]?.toLongOrNull()?.let(DriverState::setBalance)
        }
    }
}
