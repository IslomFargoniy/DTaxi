package uz.ibtaxi.driver.ui

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.verticalScroll
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material3.Button
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.text.input.VisualTransformation
import androidx.compose.material3.IconButton
import androidx.compose.material3.Icon
import androidx.compose.material.icons.filled.VisibilityOff
import androidx.compose.material.icons.filled.Visibility
import androidx.compose.material.icons.Icons
import androidx.compose.ui.unit.dp
import kotlinx.coroutines.launch
import uz.ibtaxi.driver.BuildConfig
import uz.ibtaxi.driver.IbTaxiApp
import uz.ibtaxi.driver.R
import uz.ibtaxi.driver.data.Formats
import uz.ibtaxi.driver.data.Result
import androidx.compose.ui.text.AnnotatedString
import androidx.compose.ui.text.input.OffsetMapping
import androidx.compose.ui.text.input.TransformedText

/**
 * Kirish: telefon + admin bergan parol (SMS yo'q — qaror #4).
 */
@OptIn(androidx.compose.foundation.layout.ExperimentalLayoutApi::class)
@Composable
fun LoginScreen(onLocaleChange: (String) -> Unit = {}) {
    val repository = IbTaxiApp.instance.repository
    val scope = rememberCoroutineScope()
    val session = IbTaxiApp.instance.session
    // Sinov ilovasi: Mac'ning joriy Wi-Fi manzili (deploy/dev-lan-proxy.py ko'rsatadi)
    var server by remember { mutableStateOf(session.serverUrl.removePrefix("http://")) }
    var phone by remember { mutableStateOf("") }
    var password by remember { mutableStateOf("") }
    var showPassword by remember { mutableStateOf(false) }
    var loading by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    val failed = stringResource(R.string.login_failed)
    val network = stringResource(R.string.network_error)

    Column(
        // Klaviatura ochilganda maydonlar yopilib qolmasin: ekran klaviatura ustida qoladi va aylantiriladi
        modifier = Modifier.fillMaxSize().imePadding().verticalScroll(rememberScrollState()).padding(24.dp),
        verticalArrangement = Arrangement.spacedBy(16.dp, Alignment.CenterVertically),
    ) {
        // Kirishdan oldin til (serverga kirgandan keyin yuboriladi)
        LanguageRow(session.locale, onSelect = { if (session.locale != it) onLocaleChange(it) })
        AppLogo()
        Text(stringResource(R.string.app_name), style = MaterialTheme.typography.headlineMedium)
        Text(stringResource(R.string.login_title), style = MaterialTheme.typography.bodyLarge)
        if (BuildConfig.SERVER_EDITABLE) {
            OutlinedTextField(
                value = server,
                onValueChange = { server = it.trim() },
                label = { Text(stringResource(R.string.server_address)) },
                placeholder = { Text("192.168.0.101") },
                keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Uri),
                singleLine = true,
                modifier = Modifier.fillMaxWidth(),
            )
        }
        OutlinedTextField(
            // Holatda faqat milliy qism (9 raqam); "+998" va bo'shliqlar faqat ko'rinishda
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
        OutlinedTextField(
            value = password,
            onValueChange = { password = it },
            label = { Text(stringResource(R.string.password)) },
            visualTransformation = if (showPassword) VisualTransformation.None else PasswordVisualTransformation(),
            trailingIcon = {
                IconButton(onClick = { showPassword = !showPassword }) {
                    Icon(
                        if (showPassword) Icons.Default.VisibilityOff else Icons.Default.Visibility,
                        contentDescription = stringResource(if (showPassword) R.string.hide_password else R.string.show_password),
                    )
                }
            },
            keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Password),
            singleLine = true,
            modifier = Modifier.fillMaxWidth(),
        )
        error?.let { Text(it, color = MaterialTheme.colorScheme.error) }
        Button(
            onClick = {
                loading = true
                error = null
                if (BuildConfig.SERVER_EDITABLE) {
                    session.serverUrl = if (server.startsWith("http")) server else "http://$server"
                }
                scope.launch {
                    when (val result = repository.login("+998$phone", password)) {
                        is Result.Ok -> Unit
                        is Result.Error -> error = if (result.code == -1) network else (result.message ?: failed)
                    }
                    loading = false
                }
            },
            enabled = !loading && phone.length == Formats.PHONE_NATIONAL_LENGTH && password.isNotBlank(),
            modifier = Modifier.fillMaxWidth(),
        ) {
            if (loading) CircularProgressIndicator() else Text(stringResource(R.string.login))
        }
    }
}

/** "901234567" → "90 123 45 67": kursor raqamlar orasida to'g'ri siljiydi */
object PhoneVisualTransformation : VisualTransformation {
    override fun filter(text: AnnotatedString): TransformedText {
        val digits = text.text
        val out = Formats.formatPhoneNational(digits)
        val mapping = object : OffsetMapping {
            override fun originalToTransformed(offset: Int): Int =
                offset + listOf(2, 5, 7).count { offset > it }

            override fun transformedToOriginal(offset: Int): Int =
                (offset - out.take(offset).count { it == ' ' }).coerceIn(0, digits.length)
        }
        return TransformedText(AnnotatedString(out), mapping)
    }
}
