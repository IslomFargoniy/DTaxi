package uz.dtaxi.client

import com.google.firebase.messaging.FirebaseMessagingService
import com.google.firebase.messaging.RemoteMessage
import kotlinx.coroutines.launch

/**
 * FCM (qaror #71): ilova yopiq bo'lsa bildirishnomani telefon o'zi ko'rsatadi; ochiq bo'lsa holat Reverb orqali
 * allaqachon ko'rinadi — bu yerda faqat holat serverdan yangilanadi (Reverb uzilgan bo'lishi mumkin).
 */
class PushService : FirebaseMessagingService() {
    override fun onNewToken(token: String) {
        val app = application as ClientApp
        if (app.repository.loggedIn) app.scope.launch { app.repository.registerPushToken(token) }
    }

    override fun onMessageReceived(message: RemoteMessage) {
        val app = application as ClientApp
        if (app.repository.loggedIn) app.scope.launch { app.repository.refreshCurrent() }
    }
}
