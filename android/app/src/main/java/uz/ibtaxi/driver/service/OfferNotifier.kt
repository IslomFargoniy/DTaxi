package uz.ibtaxi.driver.service

import android.app.NotificationManager
import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import androidx.core.app.NotificationCompat
import uz.ibtaxi.driver.IbTaxiApp
import uz.ibtaxi.driver.R
import uz.ibtaxi.driver.data.Offer
import uz.ibtaxi.driver.ui.MainActivity

/**
 * Taklif kelganda: to'liq ekranli bildirishnoma (ekran qulflangan bo'lsa ham ochiladi) va signal (OfferSound).
 */
object OfferNotifier {
    private const val OFFER_ID = 2001
    private const val MESSAGE_ID = 2002

    fun show(context: Context, offer: Offer) {
        OfferSound.start(context, offer.offerId, offer.expiresIn)
        val intent = PendingIntent.getActivity(
            context,
            0,
            Intent(context, MainActivity::class.java).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_SINGLE_TOP),
            PendingIntent.FLAG_IMMUTABLE or PendingIntent.FLAG_UPDATE_CURRENT,
        )
        val notification = NotificationCompat.Builder(context, IbTaxiApp.CHANNEL_OFFERS)
            .setSmallIcon(R.drawable.ic_notification)
            .setContentTitle(context.getString(R.string.new_order))
            .setContentText(offer.pickupText)
            .setCategory(NotificationCompat.CATEGORY_CALL)
            .setPriority(NotificationCompat.PRIORITY_MAX)
            .setFullScreenIntent(intent, true)
            .setContentIntent(intent)
            .setAutoCancel(true)
            .setTimeoutAfter(offer.expiresIn * 1000L)
            .build()
        context.getSystemService(NotificationManager::class.java).notify(OFFER_ID, notification)
    }

    fun cancel(context: Context) {
        OfferSound.stop()
        context.getSystemService(NotificationManager::class.java).cancel(OFFER_ID)
    }

    fun message(context: Context, body: String) {
        val intent = PendingIntent.getActivity(
            context,
            1,
            Intent(context, MainActivity::class.java).putExtra("open", "chat").addFlags(Intent.FLAG_ACTIVITY_NEW_TASK),
            PendingIntent.FLAG_IMMUTABLE or PendingIntent.FLAG_UPDATE_CURRENT,
        )
        val notification = NotificationCompat.Builder(context, IbTaxiApp.CHANNEL_MESSAGES)
            .setSmallIcon(R.drawable.ic_notification)
            .setContentTitle(context.getString(R.string.chat))
            .setContentText(body)
            .setContentIntent(intent)
            .setAutoCancel(true)
            .build()
        context.getSystemService(NotificationManager::class.java).notify(MESSAGE_ID, notification)
    }
}
