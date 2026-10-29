package uz.dtaxi.client.ui

import android.content.Context
import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import androidx.compose.foundation.isSystemInDarkTheme
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.material3.Button
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.lifecycle.lifecycleScope
import kotlinx.coroutines.flow.distinctUntilChanged
import kotlinx.coroutines.flow.map
import kotlinx.coroutines.launch
import uz.dtaxi.client.BuildConfig
import uz.dtaxi.client.ClientApp
import uz.dtaxi.client.R
import uz.dtaxi.client.data.ClientState
import uz.dtaxi.client.data.LatLng
import uz.dtaxi.client.data.Result
import uz.dtaxi.core.data.AppLocale
import uz.dtaxi.core.ui.AppTheme

private sealed interface Screen {
    data object Home : Screen
    data class Search(val target: SearchTarget) : Screen
    data object History : Screen
    data object Settings : Screen
    data object AddAddress : Screen
}

class MainActivity : ComponentActivity() {
    private val app get() = application as ClientApp

    override fun attachBaseContext(base: Context) {
        // Ilova tili telefon tilidan qat'i nazar (uz, oz — kirill, ru, en)
        super.attachBaseContext(AppLocale.wrap(base, ClientApp.instance.session.locale))
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        enableEdgeToEdge()
        // Profil yuklangach (kirish yoki ilova ochilishi) — Reverb ulanadi
        lifecycleScope.launch {
            ClientState.profile.map { it?.id }.distinctUntilChanged().collect { id ->
                app.stopRealtime()
                if (id != null) {
                    app.startRealtime()
                    app.registerPush()
                }
            }
        }
        setContent {
            val mode by ClientState.themeMode.collectAsState()
            AppTheme(BuildConfig.PRIMARY_COLOR, mode) {
                val dark = when (mode) {
                    "light" -> false
                    "dark" -> true
                    else -> isSystemInDarkTheme()
                }
                Root(dark, ::changeLocale, ::changeTheme)
            }
        }
    }

    override fun onStart() {
        super.onStart()
        if (ClientState.profile.value != null) app.startRealtime()
        // Fonda bo'lgan paytdagi o'zgarishlar — serverdan
        if (app.repository.loggedIn) lifecycleScope.launch { app.repository.refreshCurrent() }
    }

    override fun onStop() {
        super.onStop()
        app.stopRealtime()
    }

    private fun changeLocale(code: String) {
        app.session.locale = code
        // recreate() Activity scope'ini bekor qiladi — so'rov ilova scope'ida, aks holda serverga yetmay qolardi
        if (app.repository.loggedIn) app.scope.launch { app.repository.updateProfile(locale = code) }
        recreate()
    }

    private fun changeTheme(mode: String) {
        app.session.themeMode = mode
        ClientState.setThemeMode(mode)
    }
}

@Composable
private fun Root(dark: Boolean, onLocaleChange: (String) -> Unit, onThemeChange: (String) -> Unit) {
    val repository = ClientApp.instance.repository
    val profile by ClientState.profile.collectAsState()
    val activeOrder by ClientState.order.collectAsState()
    var loading by remember { mutableStateOf(repository.loggedIn) }
    var failed by remember { mutableStateOf(false) }
    var attempt by remember { mutableIntStateOf(0) }
    var screen by remember { mutableStateOf<Screen>(Screen.Home) }
    val draft = remember { OrderDraft() }
    val controller = remember { MapController() }

    // Ilova ochilganda: token bor — profil va faol buyurtma
    LaunchedEffect(attempt) {
        if (!repository.loggedIn || profile != null) {
            loading = false
            return@LaunchedEffect
        }
        loading = true
        failed = false
        when (val result = repository.refreshProfile()) {
            is Result.Ok -> {
                // Til oflayn paytda o'zgartirilgan bo'lsa — server (SMS, push matnlari) ham shu tilda bo'lsin
                val locale = ClientApp.instance.session.locale
                if (AppLocale.normalize(result.value.locale) != locale) ClientApp.instance.scope.launch { repository.updateProfile(locale = locale) }
                repository.refreshCurrent()
            }
            is Result.Error -> failed = repository.loggedIn
        }
        loading = false
    }

    // Chiqish (yoki hisob o'chirilishi, 401): keyingi kirishda bosh ekran va bo'sh qoralama
    LaunchedEffect(profile == null) {
        if (profile == null) {
            screen = Screen.Home
            draft.reset()
        }
    }

    var nameSkipped by remember { mutableStateOf(ClientApp.instance.session.nameSkipped) }
    val current = profile
    when {
        loading -> Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) { CircularProgressIndicator() }
        failed -> Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
            Button(onClick = { attempt++ }) { Text(stringResource(R.string.network_error)) }
        }
        current == null -> LoginScreen(onLocaleChange)
        current.needsName && !nameSkipped -> NameScreen(onSkip = {
            ClientApp.instance.session.nameSkipped = true
            nameSkipped = true
        })
        // Bosh ekran doim chizilgan — qidiruv/tarix/sozlamalar ustidan ochiladi: xarita va tanlangan nuqta saqlanadi
        else -> Box(Modifier.fillMaxSize()) {
            HomeScreen(
                profile = current,
                draft = draft,
                controller = controller,
                dark = dark,
                onSearch = { screen = Screen.Search(it) },
                onHistory = { screen = Screen.History },
                onSettings = { screen = Screen.Settings },
            )
            if (screen != Screen.Home) {
                Surface(Modifier.fillMaxSize(), color = MaterialTheme.colorScheme.background) {
                    when (val s = screen) {
                        Screen.Home -> Unit
                        is Screen.Search -> SearchScreen(
                            target = s.target,
                            near = draft.pickup?.let { LatLng(it.lat, it.lng) } ?: current.config.mapCenter,
                            onPick = { place ->
                                if (s.target == SearchTarget.Pickup) {
                                    draft.pickup = place
                                    draft.pickupFromSearch = true
                                    controller.moveTo(LatLng(place.lat, place.lng))
                                } else {
                                    draft.dropoff = place
                                }
                                screen = Screen.Home
                            },
                            onBack = { screen = Screen.Home },
                            // "Qayerga" xaritadan: bosh ekranda pin B nuqtasini tanlaydi
                            onMap = if (s.target == SearchTarget.Dropoff) {
                                {
                                    draft.dropoffCandidate = null
                                    draft.choosingDropoff = true
                                    (draft.dropoff ?: draft.pickup)?.let { controller.moveTo(LatLng(it.lat, it.lng)) }
                                    screen = Screen.Home
                                }
                            } else {
                                null
                            },
                        )
                        Screen.History -> HistoryScreen(
                            onBack = { screen = Screen.Home },
                            canReorder = activeOrder == null,
                            onReorder = { order ->
                                draft.choosingDropoff = false
                                draft.pickup = order.pickup
                                draft.pickupFromSearch = true
                                draft.dropoff = order.dropoff
                                draft.tariffId = order.tariffClass.id
                                draft.comment = order.comment.orEmpty()
                                controller.moveTo(LatLng(order.pickup.lat, order.pickup.lng))
                                screen = Screen.Home
                            },
                        )
                        Screen.Settings -> SettingsScreen(
                            current,
                            onLocaleChange,
                            onThemeChange,
                            onAddAddress = { screen = Screen.AddAddress },
                            onBack = { screen = Screen.Home },
                        )
                        Screen.AddAddress -> AddAddressScreen(
                            near = draft.pickup?.let { LatLng(it.lat, it.lng) } ?: current.config.mapCenter,
                            onDone = { screen = Screen.Settings },
                        )
                    }
                }
            }
        }
    }
}
