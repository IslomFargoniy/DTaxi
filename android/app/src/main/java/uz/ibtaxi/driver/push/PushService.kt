package uz.ibtaxi.driver.push

import com.google.firebase.messaging.FirebaseMessagingService
import com.google.firebase.messaging.RemoteMessage
import com.google.gson.Gson
import com.google.gson.JsonObject
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import uz.ibtaxi.driver.IbTaxiApp
import uz.ibtaxi.driver.data.DriverState
import uz.ibtaxi.driver.data.Offer
import uz.ibtaxi.driver.service.OfferNotifier

/**
 * FCM: ilova fonda yoki yopiq bo'lsa uyg'otadi (qaror #1). Reverb bilan takrorlansa offer_id bo'yicha tashlanadi.
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
                // Server push'da bool'ni "1"/"" qilib yuboradi (PHP strval) — Gson buni false deb o'qirdi
                val booleans = setOf("is_direct", "is_delivery", "is_corporate")
                val json = JsonObject().apply {
                    data.forEach { (k, v) -> if (k in booleans) addProperty(k, v == "1" || v == "true") else addProperty(k, v) }
                }
                val offer = runCatching { gson.fromJson(json, Offer::class.java) }.getOrNull() ?: return
                if (DriverState.offer(offer)) OfferNotifier.show(this, offer)
            }
            "chat.message" -> data["body"]?.let { OfferNotifier.message(this, it) }
            "balance.topup" -> data["balance"]?.toLongOrNull()?.let(DriverState::setBalance)
        }
    }

    companion object {
        private val gson = Gson()
    }
}
