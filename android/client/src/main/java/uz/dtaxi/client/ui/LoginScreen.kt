package uz.dtaxi.client.ui

import androidx.compose.foundation.Image
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.systemBarsPadding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.Button
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.res.painterResource
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import uz.dtaxi.client.ClientApp
import uz.dtaxi.client.R
import uz.dtaxi.client.data.Result
import uz.dtaxi.core.data.Formats
import uz.dtaxi.core.ui.LanguageRow
import uz.dtaxi.core.ui.PhoneVisualTransformation

/** Kod qayta so'ralguncha kutish (server raqamga soatiga 3 ta qo'ng'iroq beradi) */
private const val RESEND_SECONDS = 60

/**
 * Kirish (qaror #71): telefon → qo'ng'iroqda aytilgan 4 xonali kod. SMS yo'q (qaror #4).
 */
@Composable
fun LoginScreen(onLocaleChange: (String) -> Unit) {
    val repository = ClientApp.instance.repository
    val session = ClientApp.instance.session
    val scope = rememberCoroutineScope()
    var phone by remember { mutableStateOf("") }
    var codeSent by remember { mutableStateOf(false) }
    var code by remember { mutableStateOf("") }
    var loading by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    var wait by remember { mutableIntStateOf(0) }
    val network = stringResource(R.string.network_error)
    val generic = stringResource(R.string.error)
    val tooMany = stringResource(R.string.too_many_requests)

    fun message(result: Result.Error) = when (result.code) {
        -1 -> network
        429 -> tooMany
        else -> result.message ?: generic
    }

    fun sendCode() {
        loading = true
        error = null
        scope.launch {
            when (val result = repository.requestCode("+998$phone")) {
                is Result.Ok -> {
                    codeSent = true
                    code = ""
                    wait = RESEND_SECONDS
                }
                is Result.Error -> error = message(result)
            }
            loading = false
        }
    }

    fun verify() {
        loading = true
        error = null
        scope.launch {
            // Muvaffaqiyatda profil holatga yoziladi — MainActivity o'zi keyingi ekranga o'tadi
            (repository.verify("+998$phone", code) as? Result.Error)?.let { error = message(it) }
            loading = false
        }
    }

    LaunchedEffect(wait) {
        if (wait > 0) {
            delay(1_000)
            wait--
        }
    }
    // 4 raqam terilishi bilan tekshiriladi
    LaunchedEffect(code) {
        if (code.length == 4 && !loading) verify()
    }

    Column(
        modifier = Modifier.fillMaxSize().systemBarsPadding().imePadding().verticalScroll(rememberScrollState()).padding(24.dp),
        verticalArrangement = Arrangement.spacedBy(16.dp, Alignment.CenterVertically),
    ) {
        LanguageRow(session.locale, onSelect = { if (session.locale != it) onLocaleChange(it) })
        Image(
            painter = painterResource(R.drawable.ic_launcher),
            contentDescription = stringResource(R.string.app_name),
            modifier = Modifier.size(88.dp).clip(RoundedCornerShape(22.dp)),
        )
        Text(stringResource(R.string.app_name), style = MaterialTheme.typography.headlineMedium)

        if (!codeSent) {
            Text(stringResource(R.string.login_title), style = MaterialTheme.typography.bodyLarge)
            OutlinedTextField(
                value = phone,
                onValueChange = { phone = Formats.phoneInput(it) },
                label = { Text(stringResource(R.string.phone)) },
                prefix = { Text("+998 ") },
                placeholder = { Text("90 123 45 67") },
                visualTransformation = PhoneVisualTransformation,
                keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Phone, autoCorrectEnabled = false),
                singleLine = true,
                modifier = Modifier.fillMaxWidth(),
            )
            Text(stringResource(R.string.code_hint), style = MaterialTheme.typography.bodySmall)
            error?.let { Text(it, color = MaterialTheme.colorScheme.error) }
            Button(
                onClick = ::sendCode,
                enabled = !loading && phone.length == Formats.PHONE_NATIONAL_LENGTH,
                modifier = Modifier.fillMaxWidth(),
            ) {
                if (loading) CircularProgressIndicator(Modifier.size(20.dp)) else Text(stringResource(R.string.get_code))
            }
        } else {
            Text(stringResource(R.string.code_title), style = MaterialTheme.typography.bodyLarge)
            Text(Formats.formatPhone("+998$phone"), style = MaterialTheme.typography.titleMedium)
            OutlinedTextField(
                value = code,
                onValueChange = { value -> code = value.filter(Char::isDigit).take(4) },
                label = { Text(stringResource(R.string.code)) },
                keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.NumberPassword),
                singleLine = true,
                modifier = Modifier.fillMaxWidth(),
            )
            error?.let { Text(it, color = MaterialTheme.colorScheme.error) }
            Button(onClick = ::verify, enabled = !loading && code.length == 4, modifier = Modifier.fillMaxWidth()) {
                if (loading) CircularProgressIndicator(Modifier.size(20.dp)) else Text(stringResource(R.string.continue_))
            }
            TextButton(onClick = ::sendCode, enabled = wait == 0 && !loading, modifier = Modifier.fillMaxWidth()) {
                Text(if (wait > 0) stringResource(R.string.resend_in, wait) else stringResource(R.string.resend_code))
            }
            TextButton(
                onClick = {
                    codeSent = false
                    error = null
                },
                modifier = Modifier.fillMaxWidth(),
            ) { Text(stringResource(R.string.change_phone)) }
        }
    }
}

/** Birinchi kirishda ism (haydovchi mijozni shunday chaqiradi) */
@Composable
fun NameScreen() {
    val repository = ClientApp.instance.repository
    val scope = rememberCoroutineScope()
    var name by remember { mutableStateOf("") }
    var loading by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    val generic = stringResource(R.string.error)

    Column(
        modifier = Modifier.fillMaxSize().systemBarsPadding().imePadding().padding(24.dp),
        verticalArrangement = Arrangement.spacedBy(16.dp, Alignment.CenterVertically),
    ) {
        Text(stringResource(R.string.name_title), style = MaterialTheme.typography.headlineSmall)
        OutlinedTextField(
            value = name,
            onValueChange = { name = it.take(100) },
            label = { Text(stringResource(R.string.your_name)) },
            singleLine = true,
            modifier = Modifier.fillMaxWidth(),
        )
        error?.let { Text(it, color = MaterialTheme.colorScheme.error) }
        Button(
            onClick = {
                loading = true
                scope.launch {
                    (repository.updateProfile(name = name.trim()) as? Result.Error)?.let { error = it.message ?: generic }
                    loading = false
                }
            },
            enabled = !loading && name.isNotBlank(),
            modifier = Modifier.fillMaxWidth(),
        ) { Text(stringResource(R.string.continue_)) }
    }
}
