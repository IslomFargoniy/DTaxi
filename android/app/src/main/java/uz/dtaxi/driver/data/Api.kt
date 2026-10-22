package uz.dtaxi.driver.data

import retrofit2.Response
import retrofit2.http.Body
import retrofit2.http.GET
import retrofit2.http.POST
import retrofit2.http.PUT
import retrofit2.http.Path
import retrofit2.http.Query

/**
 * Haydovchi ilovasi API (backend: routes/api.php, /api/driver/v1).
 */
interface Api {
    @POST("api/driver/v1/auth/login")
    suspend fun login(@Body body: LoginRequest): Response<LoginResponse>

    @POST("api/driver/v1/auth/logout")
    suspend fun logout(): Response<Unit>

    @GET("api/driver/v1/me")
    suspend fun me(): Response<DataEnvelope<DriverProfile>>

    @POST("api/driver/v1/shift")
    suspend fun shift(@Body body: ShiftRequest): Response<ShiftResponse>

    @GET("api/driver/v1/shift")
    suspend fun shiftSummary(): Response<ShiftSummaryResponse>

    @PUT("api/driver/v1/locale")
    suspend fun setLocale(@Body body: LocaleRequest): Response<LocaleResponse>

    @POST("api/driver/v1/location")
    suspend fun location(@Body body: LocationBatch): Response<Unit>

    @POST("api/driver/v1/device-token")
    suspend fun deviceToken(@Body body: TokenRequest): Response<Unit>

    @GET("api/driver/v1/orders/current")
    suspend fun currentOrder(): Response<OrderEnvelope>

    @POST("api/driver/v1/orders/{id}/accept")
    suspend fun accept(@Path("id") id: Int): Response<OrderEnvelope>

    @POST("api/driver/v1/orders/{id}/reject")
    suspend fun reject(@Path("id") id: Int): Response<Unit>

    @POST("api/driver/v1/orders/{id}/arrive")
    suspend fun arrive(@Path("id") id: Int): Response<OrderEnvelope>

    @POST("api/driver/v1/orders/{id}/start")
    suspend fun start(@Path("id") id: Int, @Body body: PositionRequest): Response<OrderEnvelope>

    @POST("api/driver/v1/orders/{id}/complete")
    suspend fun complete(@Path("id") id: Int, @Body body: CompleteRequest): Response<OrderEnvelope>

    @POST("api/driver/v1/orders/{id}/cancel")
    suspend fun cancel(@Path("id") id: Int, @Body body: CancelRequest): Response<OrderEnvelope>

    @GET("api/driver/v1/route")
    suspend fun route(
        @Query("from_lat") fromLat: Double,
        @Query("from_lng") fromLng: Double,
        @Query("to_lat") toLat: Double,
        @Query("to_lng") toLng: Double,
    ): Response<RouteResponse>

    @GET("api/driver/v1/orders/history")
    suspend fun orderHistory(@Query("page") page: Int): Response<OrderHistoryPage>

    @GET("api/driver/v1/orders/{id}/track")
    suspend fun orderTrack(@Path("id") id: Int): Response<OrderTrack>

    @GET("api/driver/v1/balance")
    suspend fun balance(): Response<BalanceResponse>

    @GET("api/driver/v1/chat")
    suspend fun chat(): Response<List<ChatMessage>>

    @POST("api/driver/v1/chat")
    suspend fun sendChat(@Body body: ChatRequest): Response<ChatMessage>

    @POST("api/driver/v1/phone/calls/{id}/decline")
    suspend fun declineCall(@Path("id") id: Int): Response<Unit>

    @GET("api/driver/v1/app-version")
    suspend fun appVersion(@Query("abi") abi: String): Response<AppVersion>
}

/** Laravel JsonResource javobi: {"data": {...}} */
data class DataEnvelope<T>(val data: T)
