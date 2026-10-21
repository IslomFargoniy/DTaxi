package uz.ibtaxi.driver.data

import kotlinx.coroutines.withContext
import kotlinx.coroutines.Dispatchers
import android.os.Build
import retrofit2.Response
import java.io.IOException
import kotlin.coroutines.cancellation.CancellationException

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
                DriverState.setProfile(it.value.driver)
                // Kirish ekranida tanlangan til saqlanadi — serverga ham shu til
                if (AppLocale.normalize(it.value.driver.locale) != session.locale) setLocale(session.locale)
            }
        }.map { it.driver }

    suspend fun logout() {
        unitCall { api.logout() }
        session.clear()
        // Keyingi haydovchiga eski buyurtmaning "Yakunlash"i qayta yuborilmasin
        session.pendingComplete = null
        DriverState.setPendingComplete(null)
        DriverState.setProfile(null)
        DriverState.setOrder(null)
    }

    suspend fun refreshProfile(): Result<DriverProfile> =
        call { api.me() }.map { it.data }.also { if (it is Result.Ok) DriverState.setProfile(it.value) }

    suspend fun setOnline(online: Boolean): Result<ShiftResponse> =
        call { api.shift(ShiftRequest(online)) }.also { if (it is Result.Ok) DriverState.setStatus(it.value.status) }

    suspend fun sendLocations(points: List<LocationPoint>): Boolean = unitCall { api.location(LocationBatch(points)) } is Result.Ok

    suspend fun registerPushToken(token: String) = unitCall { api.deviceToken(TokenRequest(token)) }

    /** Operator qo'ng'irog'ini rad etish */
    suspend fun declineCall(callId: Int) = unitCall { api.declineCall(callId) }

    suspend fun refreshOrder(): Result<Order?> =
        call { api.currentOrder() }.map { it.order }.also { if (it is Result.Ok) DriverState.setOrder(it.value) }

    suspend fun accept(orderId: Int): Result<Order?> = orderCall { api.accept(orderId) }.also {
        DriverState.clearOffer()
        if (it is Result.Ok) DriverState.setStatus("busy")
    }

    suspend fun reject(orderId: Int) {
        DriverState.clearOffer(orderId)
        unitCall { api.reject(orderId) }
    }

    suspend fun arrive(orderId: Int) = orderCall { api.arrive(orderId) }

    /** Joriy GPS joyi yuboriladi — buyurtmalar tarixida yo'lovchi qayerda olingani ko'rinadi */
    suspend fun start(orderId: Int): Result<Order?> {
        val here = DriverState.location.value
        return orderCall { api.start(orderId, PositionRequest(here?.first, here?.second)) }
    }

    /**
     * Internet yo'q bo'lsa natija saqlanadi va servis [retryPendingComplete] bilan qayta yuboradi —
     * taksometr ko'rsatkichi yo'qolmaydi (server takroriy yakunlashni muvaffaqiyat deb qaytaradi).
     */
    suspend fun complete(orderId: Int, inside: Long, outside: Long): Result<Order?> {
        session.pendingComplete = Triple(orderId, inside, outside)
        session.pendingFinishAt = DriverState.location.value
        DriverState.setPendingComplete(orderId)
        return sendComplete(orderId, inside, outside)
    }

    suspend fun retryPendingComplete(): Result<Order?>? {
        val (orderId, inside, outside) = session.pendingComplete ?: return null
        DriverState.setPendingComplete(orderId)
        return sendComplete(orderId, inside, outside)
    }

    private suspend fun sendComplete(orderId: Int, inside: Long, outside: Long): Result<Order?> =
        call {
            val at = session.pendingFinishAt
            api.complete(orderId, CompleteRequest(inside, outside, at?.first, at?.second))
        }.map { it.order }.also {
            // Tarmoq xatosi (-1) bo'lmasa server javob berdi — qayta yuborish shart emas
            if (it !is Result.Error || it.code != -1) {
                session.pendingComplete = null
                session.pendingFinishAt = null
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

    suspend fun shiftSummary() = call { api.shiftSummary() }

    /** Ilova tili serverda ham: xabarlar va xarita nomlari shu tilda */
    suspend fun setLocale(code: String): Result<LocaleResponse> =
        call { api.setLocale(LocaleRequest(code)) }.also { result ->
            if (result is Result.Ok) result.value.mapStyle?.let(DriverState::setMapStyle)
        }

    suspend fun orderHistory(page: Int) = call { api.orderHistory(page) }

    suspend fun orderTrack(orderId: Int) = call { api.orderTrack(orderId) }

    suspend fun balance() = call { api.balance() }.also { if (it is Result.Ok) DriverState.setBalance(it.value.balance) }

    suspend fun chat() = call { api.chat() }

    suspend fun sendChat(body: String, orderId: Int?) = call { api.sendChat(ChatRequest(body, orderId)) }

    /** Telefon protsessoriga mos APK (server ABI bo'yicha alohida fayl beradi) */
    suspend fun appVersion() = call { api.appVersion(Build.SUPPORTED_ABIS.firstOrNull() ?: "arm64-v8a") }

    /**
     * Eng yangi versiya: admin panelda chiqarilgan reliz yoki serverdagi /android/version.json
     * (har build'da yoziladi) — qaysi biri yangiroq bo'lsa.
     */
    suspend fun latestVersion(): AppVersion? {
        val release = (appVersion() as? Result.Ok)?.value
        val published = withContext(Dispatchers.IO) {
            runCatching {
                client.http.newCall(okhttp3.Request.Builder().url(session.serverUrl.trimEnd('/') + "/android/version.json").build())
                    .execute().use { response ->
                        if (!response.isSuccessful) null
                        else com.google.gson.Gson().fromJson(response.body?.string(), AppVersion::class.java)
                    }
            }.getOrNull()
        }

        return listOfNotNull(release?.takeIf { it.url != null }, published?.takeIf { it.url != null }).maxByOrNull { it.versionCode }
    }

    private suspend fun orderCall(block: suspend () -> Response<OrderEnvelope>): Result<Order?> =
        call(block).map { it.order }.also { if (it is Result.Ok) DriverState.setOrder(it.value) }

    /** Javob tanasi shart: bo'sh kelsa (204) — xato (oldin `Unit as T` keyinroq ClassCastException berardi) */
    private suspend fun <T : Any> call(block: suspend () -> Response<T>): Result<T> =
        send(block) { body, code -> if (body != null) Result.Ok(body) else Result.Error(null, code) }

    /** Tanasiz javob kutiladigan so'rovlar (204 ham muvaffaqiyat) */
    private suspend fun unitCall(block: suspend () -> Response<Unit>): Result<Unit> = send(block) { _, _ -> Result.Ok(Unit) }

    /**
     * Har qanday xato Result.Error bo'ladi — servisning syncLoop'i yiqilmasin.
     * -1: tarmoq (qayta yuboriladi), -2: server javobi o'qilmadi (JSON).
     */
    private suspend fun <T, R> send(block: suspend () -> Response<T>, onSuccess: (T?, Int) -> Result<R>): Result<R> = try {
        val response = block()
        when {
            response.isSuccessful -> onSuccess(response.body(), response.code())
            response.code() == 401 -> {
                session.clear()
                DriverState.setProfile(null)
                Result.Error(null, 401)
            }
            else -> Result.Error(ApiClient.errorMessage(response), response.code())
        }
    } catch (e: CancellationException) {
        throw e
    } catch (e: IOException) {
        Result.Error(null, -1)
    } catch (e: Exception) {
        Result.Error(null, -2)
    }

    private inline fun <T, R> Result<T>.map(transform: (T) -> R): Result<R> = when (this) {
        is Result.Ok -> Result.Ok(transform(value))
        is Result.Error -> this
    }
}
