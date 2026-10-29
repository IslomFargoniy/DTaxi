package uz.dtaxi.driver.ui

import android.content.Intent
import android.net.Uri
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardActions
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Visibility
import androidx.compose.material.icons.filled.VisibilityOff
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.text.input.VisualTransformation
import androidx.compose.ui.text.style.TextDecoration
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import kotlinx.coroutines.launch
import uz.dtaxi.core.data.Phones
import uz.dtaxi.core.ui.BigButton
import uz.dtaxi.core.ui.LanguageChip
import uz.dtaxi.core.ui.LocalAppColors
import uz.dtaxi.core.ui.PhoneField
import uz.dtaxi.core.ui.TileTextField
import uz.dtaxi.driver.BuildConfig
import uz.dtaxi.driver.DtaxiApp
import uz.dtaxi.driver.R
import uz.dtaxi.driver.data.Result
import uz.dtaxi.driver.data.Session

/**
 * Kirish: telefon + admin bergan parol (SMS yo'q — qaror #4). Tepada firma rangida logo, pastda oq varaqda shakl.
 */
@Composable
fun LoginScreen(onLocaleChange: (String) -> Unit = {}) {
    val repository = DtaxiApp.instance.repository
    val scope = rememberCoroutineScope()
    val session = DtaxiApp.instance.session
    val context = LocalContext.current
    // Sinov ilovasi: Mac'ning joriy Wi-Fi manzili (deploy/dev-lan-proxy.py ko'rsatadi)
    var server by remember { mutableStateOf(session.serverUrl.removePrefix("http://")) }
    var country by remember { mutableStateOf(Phones.defaultCountry(context, BuildConfig.PHONE_COUNTRY)) }
    var phone by remember { mutableStateOf("") }
    var password by remember { mutableStateOf("") }
    var showPassword by remember { mutableStateOf(false) }
    var loading by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    val failed = stringResource(R.string.login_failed)
    val network = stringResource(R.string.network_error)
    val invalidServer = stringResource(R.string.server_invalid)
    val brand = MaterialTheme.colorScheme.primary
    val onBrand = MaterialTheme.colorScheme.onPrimary
    val canSubmit = !loading && Phones.isValid(country, phone) && password.isNotBlank()

    fun submit() {
        if (!canSubmit) return
        loading = true
        error = null
        if (BuildConfig.SERVER_EDITABLE) {
            val url = if (server.startsWith("http")) server else "http://$server"
            // Noto'g'ri manzil saqlanmaydi — xato shu yerda ko'rinadi
            if (!Session.isValidServer(url)) {
                error = invalidServer
                loading = false
                return
            }
            session.serverUrl = url
        }
        scope.launch {
            when (val result = repository.login(Phones.e164(country, phone), password)) {
                is Result.Ok -> Unit
                is Result.Error -> error = if (result.code == -1) network else (result.message ?: failed)
            }
            loading = false
        }
    }

    Column(Modifier.fillMaxSize().background(brand).imePadding()) {
        Row(Modifier.fillMaxWidth().statusBarsPadding().padding(horizontal = 20.dp, vertical = 12.dp), horizontalArrangement = Arrangement.End) {
            // Kirishdan oldin til (serverga kirgandan keyin yuboriladi)
            LanguageChip(session.locale, onSelect = onLocaleChange, container = onBrand.copy(alpha = 0.08f))
        }
        Box(Modifier.weight(1f).fillMaxWidth(), contentAlignment = Alignment.Center) {
            Column(horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.spacedBy(12.dp)) {
                AppLogo(84)
                Text(stringResource(R.string.app_name), color = onBrand, fontSize = 30.sp, fontWeight = FontWeight.ExtraBold)
                Text(stringResource(R.string.driver_app), color = onBrand.copy(alpha = 0.75f), fontWeight = FontWeight.Medium)
            }
        }
        Column(
            Modifier
                .fillMaxWidth()
                .clip(RoundedCornerShape(topStart = 28.dp, topEnd = 28.dp))
                .background(MaterialTheme.colorScheme.surface)
                .verticalScroll(rememberScrollState())
                .navigationBarsPadding()
                .padding(start = 20.dp, end = 20.dp, top = 28.dp, bottom = 20.dp),
            verticalArrangement = Arrangement.spacedBy(14.dp),
        ) {
            Column(verticalArrangement = Arrangement.spacedBy(4.dp)) {
                Text(stringResource(R.string.login), fontSize = 24.sp, fontWeight = FontWeight.Bold)
                Text(stringResource(R.string.login_subtitle), color = LocalAppColors.current.muted)
            }
            if (BuildConfig.SERVER_EDITABLE) {
                Label(stringResource(R.string.server_address))
                TileTextField(
                    value = server,
                    onValueChange = { server = it.trim() },
                    placeholder = "192.168.0.101",
                    keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Uri, imeAction = ImeAction.Next),
                )
            }
            Label(stringResource(R.string.phone))
            PhoneField(country, phone, onChange = { iso, digits ->
                country = iso
                phone = digits
            })
            Label(stringResource(R.string.password))
            TileTextField(
                value = password,
                onValueChange = { password = it },
                placeholder = stringResource(R.string.password_placeholder),
                visualTransformation = if (showPassword) VisualTransformation.None else PasswordVisualTransformation(),
                keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Password, imeAction = ImeAction.Go),
                keyboardActions = KeyboardActions(onGo = { submit() }),
                trailing = {
                    IconButton(onClick = { showPassword = !showPassword }) {
                        Icon(
                            if (showPassword) Icons.Default.VisibilityOff else Icons.Default.Visibility,
                            contentDescription = stringResource(if (showPassword) R.string.hide_password else R.string.show_password),
                            tint = LocalAppColors.current.muted,
                        )
                    }
                },
            )
            error?.let { Text(it, color = MaterialTheme.colorScheme.error) }
            BigButton(stringResource(R.string.login), onClick = ::submit, enabled = canSubmit, loading = loading, modifier = Modifier.padding(top = 4.dp))
            ForgotPassword(onCall = {
                context.startActivity(Intent(Intent.ACTION_DIAL, Uri.parse("tel:${BuildConfig.DISPATCHER_PHONE}")))
            })
        }
    }
}

@Composable
private fun Label(text: String) {
    Text(text, style = MaterialTheme.typography.labelLarge, fontWeight = FontWeight.SemiBold, modifier = Modifier.padding(bottom = 0.dp))
}

/** Parol faqat dispetcherda — firma raqami berilgan bo'lsa bir bosishda qo'ng'iroq */
@Composable
private fun ForgotPassword(onCall: () -> Unit) {
    val muted = LocalAppColors.current.muted
    if (BuildConfig.DISPATCHER_PHONE.isBlank()) {
        Text(
            stringResource(R.string.ask_dispatcher),
            color = muted,
            style = MaterialTheme.typography.bodyMedium,
            modifier = Modifier.fillMaxWidth().padding(top = 4.dp),
            textAlign = androidx.compose.ui.text.style.TextAlign.Center,
        )
        return
    }
    Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.Center, verticalAlignment = Alignment.CenterVertically) {
        Text(stringResource(R.string.forgot_password), color = muted, style = MaterialTheme.typography.bodyMedium)
        TextButton(onClick = onCall) {
            Text(
                stringResource(R.string.call_dispatcher),
                fontWeight = FontWeight.SemiBold,
                textDecoration = TextDecoration.Underline,
                color = MaterialTheme.colorScheme.onSurface,
            )
        }
    }
}
