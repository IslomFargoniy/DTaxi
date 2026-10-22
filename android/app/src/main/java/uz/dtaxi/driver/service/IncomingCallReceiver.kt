package uz.dtaxi.driver.service

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent

/**
 * Bildirishnomadagi "Rad etish" — ekran ochilmasdan. So'rov tugaguncha jarayon tirik qoladi (goAsync).
 */
class IncomingCallReceiver : BroadcastReceiver() {
    override fun onReceive(context: Context, intent: Intent) {
        if (intent.action != IncomingCallNotifier.ACTION_DECLINE) return
        val job = IncomingCallNotifier.decline(context) ?: return
        val pending = goAsync()
        job.invokeOnCompletion { pending.finish() }
    }
}
