package uz.dtaxi.driver.ui

import androidx.compose.runtime.Composable
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import uz.dtaxi.core.ui.AppTheme
import uz.dtaxi.driver.BuildConfig
import uz.dtaxi.driver.data.DriverState

/** Haydovchi ilovasi mavzusi: firma rangi va sozlamadagi tanlov (tizim / kunduzgi / tungi) */
@Composable
fun DtaxiTheme(content: @Composable () -> Unit) {
    val mode by DriverState.themeMode.collectAsState()
    AppTheme(BuildConfig.PRIMARY_COLOR, mode, content)
}
