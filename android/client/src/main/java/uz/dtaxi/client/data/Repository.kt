package uz.dtaxi.client.data

import com.google.gson.Gson
import kotlinx.coroutines.CancellationException
import retrofit2.Response
import retrofit2.Retrofit
import retrofit2.converter.gson.GsonConverterFactory
import uz.dtaxi.client.BuildConfig
import uz.dtaxi.core.net.HttpClients
import java.io.IOException

sealed interface Result<out T> {
    data class Ok<T>(val value: T) : Result<T>

    /** code: -1 — tarmoq, -2 — javob o'qilmadi, 429 — juda ko'p urinish */
    data class Error(val message: String?, val code: Int = 0) : Result<Nothing>
}

class Repository(private val session: Session) {
    private val gson = Gson()
    val http = HttpClients.create({ BuildConfig.SERVER_URL }, { session.token }, { session.locale }, BuildConfig.DEBUG)
    private val api: Api = Retrofit.Builder()
        .baseUrl(BuildConfig.SERVER_URL + "/")
        .client(http)
        .addConverterFactory(GsonConverterFactory.create())
        .build()
        .create(Api::class.java)

    val loggedIn: Boolean get() = session.token != null

    suspend fun requestCode(phone: String) = unitCall { api.requestCode(CodeRequest(phone)) }

    suspend fun verify(phone: String, code: String): Result<Profile> =
        call { api.verify(VerifyRequest(phone, code, android.os.Build.MODEL ?: "Android")) }.map { response ->
            session.token = response.token
            ClientState.setProfile(response.client)
            response.client
        }

    suspend fun logout() {
        unitCall { api.logout() }
        session.clear()
        ClientState.reset()
    }

    suspend fun refreshProfile(): Result<Profile> = call { api.me() }.map { it.also(ClientState::setProfile) }

    suspend fun updateProfile(name: String? = null, locale: String? = null): Result<Profile> =
        call { api.updateProfile(ProfileUpdate(name, locale)) }.map { it.also(ClientState::setProfile) }

    suspend fun places(query: String, near: LatLng?) = call { api.places(query, near?.lat, near?.lng) }

    suspend fun reverse(lat: Double, lng: Double): Result<String?> = call { api.reverse(lat, lng) }.map { it.text }

    suspend fun addresses() = call { api.addresses() }

    suspend fun saveAddress(label: String, place: Place) =
        call { api.saveAddress(AddressRequest(label, place.text.orEmpty(), place.lat, place.lng)) }

    suspend fun deleteAddress(id: Int) = unitCall { api.deleteAddress(id) }

    suspend fun quote(pickup: Place, dropoff: Place?) = call { api.quote(QuoteRequest(pickup, dropoff)) }

    /** 409 — faol buyurtma allaqachon bor: server o'shani qaytaradi, ilova unga o'tadi */
    suspend fun createOrder(request: OrderRequest): Result<ClientOrder> {
        val result = send({ api.createOrder(request) }, { body, code -> if (body != null) Result.Ok(body) else Result.Error(null, code) }) { response ->
            if (response.code() == 409) {
                runCatching { gson.fromJson(response.errorBody()?.charStream(), ActiveOrderConflict::class.java)?.order }.getOrNull()
                    ?.let { Result.Ok(it) }
            } else {
                null
            }
        }
        if (result is Result.Ok) ClientState.setOrder(result.value)
        return result
    }

    suspend fun refreshCurrent(): Result<CurrentOrders> = call { api.current() }.map { current ->
        ClientState.setOrder(current.order)
        ClientState.setToRate(current.toRate)
        current
    }

    suspend fun history(beforeId: Int?) = call { api.history(beforeId) }

    suspend fun cancel(id: Int): Result<ClientOrder> = call { api.cancel(id, CancelRequest(null)) }.map { it.also(ClientState::setOrder) }

    suspend fun rate(id: Int, rating: Int): Result<ClientOrder> = call { api.rate(id, RateRequest(rating)) }.map {
        ClientState.setToRate(null)
        it
    }

    private suspend fun <T : Any> call(block: suspend () -> Response<T>): Result<T> =
        send(block, { body, code -> if (body != null) Result.Ok(body) else Result.Error(null, code) })

    private suspend fun unitCall(block: suspend () -> Response<Unit>): Result<Unit> = send(block, { _, _ -> Result.Ok(Unit) })

    /** Har qanday xato Result.Error; 401 — token yaroqsiz, kirish ekraniga qaytiladi */
    private suspend fun <T, R> send(
        block: suspend () -> Response<T>,
        onSuccess: (T?, Int) -> Result<R>,
        onFailure: (Response<T>) -> Result<R>? = { null },
    ): Result<R> = try {
        val response = block()
        when {
            response.isSuccessful -> onSuccess(response.body(), response.code())
            response.code() == 401 -> {
                session.clear()
                ClientState.reset()
                Result.Error(null, 401)
            }
            else -> onFailure(response) ?: Result.Error(HttpClients.errorMessage(response), response.code())
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
