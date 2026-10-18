package uz.ibtaxi.driver.realtime

import android.util.Log
import com.google.gson.Gson
import com.google.gson.JsonObject
import com.pusher.client.Pusher
import com.pusher.client.PusherOptions
import com.pusher.client.channel.PrivateChannelEventListener
import com.pusher.client.channel.PusherEvent
import com.pusher.client.connection.ConnectionEventListener
import com.pusher.client.connection.ConnectionState
import com.pusher.client.connection.ConnectionStateChange
import com.pusher.client.util.HttpChannelAuthorizer
import uz.ibtaxi.driver.data.ReverbConfig

/**
 * Reverb (Pusher protokoli): `private-driver.{id}` kanali — takliflar, buyurtma holati, chat (qaror #1).
 */
class Realtime(
    private val config: ReverbConfig,
    private val token: String,
    private val driverId: Int,
    private val onEvent: (name: String, data: JsonObject) -> Unit,
) {
    private var pusher: Pusher? = null

    fun connect() {
        if (pusher != null) return

        val authorizer = HttpChannelAuthorizer("${uz.ibtaxi.driver.IbTaxiApp.instance.session.serverUrl}/api/broadcasting/auth").apply {
            setHeaders(mapOf("Authorization" to "Bearer $token", "Accept" to "application/json"))
        }
        val options = PusherOptions()
            .setHost(config.host)
            .setWsPort(config.port)
            .setWssPort(config.port)
            .setUseTLS(config.scheme == "https")
            .setChannelAuthorizer(authorizer)
            .setActivityTimeout(30_000)

        pusher = Pusher(config.key, options).also { client ->
            client.connect(object : ConnectionEventListener {
                override fun onConnectionStateChange(change: ConnectionStateChange) {
                    Log.d(TAG, "connection: ${change.currentState}")
                }

                override fun onError(message: String?, code: String?, e: Exception?) {
                    Log.w(TAG, "error: $message $code", e)
                }
            }, ConnectionState.ALL)

            val listener = object : PrivateChannelEventListener {
                override fun onEvent(event: PusherEvent) {
                    val data = runCatching { gson.fromJson(event.data, JsonObject::class.java) }.getOrNull() ?: return
                    onEvent(event.eventName.substringAfterLast('\\'), data)
                }

                override fun onSubscriptionSucceeded(channelName: String) {
                    Log.d(TAG, "subscribed: $channelName")
                }

                override fun onAuthenticationFailure(message: String?, e: Exception?) {
                    Log.w(TAG, "auth failure: $message", e)
                }
            }

            client.subscribePrivate(
                "private-driver.$driverId",
                listener,
                "App\\Events\\OrderOffered",
                "App\\Events\\OfferWithdrawn",
                "App\\Events\\OrderUpdated",
                "App\\Events\\ChatMessageSent",
            )
        }
    }

    fun disconnect() {
        pusher?.disconnect()
        pusher = null
    }

    companion object {
        private const val TAG = "Realtime"
        private val gson = Gson()
    }
}
