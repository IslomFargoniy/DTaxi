package uz.ibtaxi.driver.data

import android.os.Build
import retrofit2.Response
import java.io.IOException

sealed interface Result<out T> {
    data class Ok<T>(val value: T) : Result<T>
    data class Error(val message: String?, val code: Int = 0) : Result<Nothing>
}

/**
 * API chaqiruvlari + holatni yangilash.
 */
class Repository(val client: ApiClient, val session: Session) {
    private val api = client.api

    val isLoggedIn: Boolean get() = session.token != null

    suspend fun login(phone: String, password: String): Result<DriverProfile> =
        call { api.login(LoginRequest(phone, password, Build.MODEL ?: "android")) }.also {
            if (it is Result.Ok) {
                session.token = it.value.token
                session.locale = it.value.driver.locale
                DriverState.setProfile(it.value.driver)
            }
        }.map { it.driver }

    suspend fun logout() {
        call { api.logout() }
        session.clear()
        DriverState.setProfile(null)
        DriverState.setOrder(null)
    }

    suspend fun refreshProfile(): Result<DriverProfile> =
        call { api.me() }.map { it.data }.also { if (it is Result.Ok) DriverState.setProfile(it.value) }

    suspend fun setOnline(online: Boolean): Result<ShiftResponse> =
        call { api.shift(ShiftRequest(online)) }.also { if (it is Result.Ok) DriverState.setStatus(it.value.status) }

    suspend fun sendLocations(points: List<LocationPoint>): Boolean = call { api.location(LocationBatch(points)) } is Result.Ok

    suspend fun registerPushToken(token: String) = call { api.deviceToken(TokenRequest(token)) }

    suspend fun refreshOrder(): Result<Order?> =
        call { api.currentOrder() }.map { it.order }.also { if (it is Result.Ok) DriverState.setOrder(it.value) }

    suspend fun accept(orderId: Int): Result<Order?> = orderCall { api.accept(orderId) }.also {
        DriverState.clearOffer()
        if (it is Result.Ok) DriverState.setStatus("busy")
    }

    suspend fun reject(orderId: Int) {
        DriverState.clearOffer(orderId)
        call { api.reject(orderId) }
    }

    suspend fun arrive(orderId: Int) = orderCall { api.arrive(orderId) }

    suspend fun start(orderId: Int) = orderCall { api.start(orderId) }

    /**
     * Internet yo'q bo'lsa natija saqlanadi va servis [retryPendingComplete] bilan qayta yuboradi —
     * taksometr ko'rsatkichi yo'qolmaydi (server takroriy yakunlashni muvaffaqiyat deb qaytaradi).
     */
    suspend fun complete(orderId: Int, inside: Long, outside: Long): Result<Order?> {
        session.pendingComplete = Triple(orderId, inside, outside)
        DriverState.setPendingComplete(orderId)
        return sendComplete(orderId, inside, outside)
    }

    suspend fun retryPendingComplete(): Result<Order?>? {
        val (orderId, inside, outside) = session.pendingComplete ?: return null
        DriverState.setPendingComplete(orderId)
        return sendComplete(orderId, inside, outside)
    }

    private suspend fun sendComplete(orderId: Int, inside: Long, outside: Long): Result<Order?> =
        call { api.complete(orderId, CompleteRequest(inside, outside)) }.map { it.order }.also {
            // Tarmoq xatosi (-1) bo'lmasa server javob berdi — qayta yuborish shart emas
            if (it !is Result.Error || it.code != -1) {
                session.pendingComplete = null
                DriverState.setPendingComplete(null)
            }
            if (it is Result.Ok) {
                it.value?.let(DriverState::setOrder)
                DriverState.setStatus("free")
                refreshProfile()
            }
        }

    /** Faqat server tasdiqlasa buyurtma yopiladi — aks holda ilova va server holati ajralib qoladi */
    suspend fun cancel(orderId: Int, reason: String) = orderCall { api.cancel(orderId, CancelRequest(reason)) }.also {
        if (it is Result.Ok) {
            DriverState.setOrder(null)
            DriverState.setStatus("free")
            refreshProfile()
        }
    }

    suspend fun route(fromLat: Double, fromLng: Double, toLat: Double, toLng: Double) = call { api.route(fromLat, fromLng, toLat, toLng) }

    suspend fun balance() = call { api.balance() }.also { if (it is Result.Ok) DriverState.setBalance(it.value.balance) }

    suspend fun chat() = call { api.chat() }

    suspend fun sendChat(body: String, orderId: Int?) = call { api.sendChat(ChatRequest(body, orderId)) }

    /** Telefon protsessoriga mos APK (server ABI bo'yicha alohida fayl beradi) */
    suspend fun appVersion() = call { api.appVersion(Build.SUPPORTED_ABIS.firstOrNull() ?: "arm64-v8a") }

    private suspend fun orderCall(block: suspend () -> Response<OrderEnvelope>): Result<Order?> =
        call(block).map { it.order }.also { if (it is Result.Ok) DriverState.setOrder(it.value) }

    private suspend fun <T> call(block: suspend () -> Response<T>): Result<T> = try {
        val response = block()
        when {
            response.isSuccessful -> Result.Ok(response.body() ?: @Suppress("UNCHECKED_CAST") (Unit as T))
            response.code() == 401 -> {
                session.clear()
                DriverState.setProfile(null)
                Result.Error(null, 401)
            }
            else -> Result.Error(ApiClient.errorMessage(response), response.code())
        }
    } catch (e: IOException) {
        Result.Error(null, -1)
    }

    private inline fun <T, R> Result<T>.map(transform: (T) -> R): Result<R> = when (this) {
        is Result.Ok -> Result.Ok(transform(value))
        is Result.Error -> this
    }
}
