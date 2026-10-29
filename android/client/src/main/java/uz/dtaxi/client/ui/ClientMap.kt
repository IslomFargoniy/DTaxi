package uz.dtaxi.client.ui

import android.os.Bundle
import androidx.compose.animation.core.Animatable
import androidx.compose.animation.core.LinearEasing
import androidx.compose.animation.core.tween
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.offset
import androidx.compose.foundation.layout.size
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Place
import androidx.compose.material3.Icon
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.material3.MaterialTheme
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.toArgb
import androidx.compose.ui.platform.LocalDensity
import android.view.Gravity
import androidx.compose.ui.unit.Dp
import androidx.compose.foundation.layout.padding
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalLifecycleOwner
import androidx.compose.ui.unit.dp
import androidx.compose.ui.viewinterop.AndroidView
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.LifecycleEventObserver
import org.maplibre.android.camera.CameraPosition
import org.maplibre.android.camera.CameraUpdateFactory
import org.maplibre.android.geometry.LatLngBounds
import org.maplibre.android.maps.MapLibreMap
import org.maplibre.android.maps.MapView
import org.maplibre.android.maps.Style
import org.maplibre.android.style.expressions.Expression
import org.maplibre.android.style.layers.CircleLayer
import org.maplibre.android.style.layers.Property
import org.maplibre.android.style.layers.SymbolLayer
import org.maplibre.android.style.layers.PropertyFactory
import org.maplibre.android.style.sources.GeoJsonSource
import org.maplibre.geojson.Feature
import org.maplibre.geojson.FeatureCollection
import org.maplibre.geojson.Point
import uz.dtaxi.client.data.LatLng
import org.maplibre.android.geometry.LatLng as MapLatLng

/** O'zbekiston markazi — firma filiali hali noma'lum bo'lsa */
private val UZBEKISTAN = MapLatLng(41.38, 64.59)

/**
 * Xarita buyruqlari ekrandan: joylashuvga o'tish, buyurtma nuqtalarini sig'dirish. Hammasi ko'rinadigan maydon
 * bo'yicha — yuqoridagi tugma va pastki panel (top/bottom, px) ostidagi joy hisobga olinmaydi.
 */
class MapController {
    internal var map: MapLibreMap? = null
    internal var top = 0.0
    internal var bottom = 0.0

    fun moveTo(point: LatLng, zoom: Double = 16.0) {
        val map = map ?: return
        map.animateCamera(
            CameraUpdateFactory.newCameraPosition(
                CameraPosition.Builder().target(MapLatLng(point.lat, point.lng)).zoom(zoom).padding(0.0, top, 0.0, bottom).build(),
            ),
        )
    }

    /** Hozirgi markaz (ko'rinadigan maydon o'rtasi) */
    fun center(): LatLng? = map?.cameraPosition?.target?.let { LatLng(it.latitude, it.longitude) }

    fun fit(points: List<LatLng>) {
        val map = map ?: return
        when {
            points.isEmpty() -> Unit
            points.size == 1 -> moveTo(points.first())
            else -> {
                val bounds = LatLngBounds.Builder().apply { points.forEach { include(MapLatLng(it.lat, it.lng)) } }.build()
                val margin = 120
                map.animateCamera(CameraUpdateFactory.newLatLngBounds(bounds, margin, top.toInt() + margin, margin, bottom.toInt() + margin))
            }
        }
    }
}

/**
 * Mijoz xaritasi. picking — "Qayerdan" tanlash: markazda pin, xarita to'xtaganda onCenter(markaz).
 * Belgilar (A, B, haydovchi) — berilganlari chiziladi (chaqiruvchi tanlaydi).
 */
@Composable
fun ClientMap(
    styleUrl: String,
    initialCenter: LatLng?,
    controller: MapController,
    picking: Boolean,
    pickup: LatLng?,
    dropoff: LatLng?,
    driver: LatLng?,
    modifier: Modifier = Modifier,
    // Haydovchi yo'nalishi (server yuborgan GPS burchagi); null — siljishdan hisoblanadi
    driverHeading: Float? = null,
    // Xarita ustidagi panellar: markaz (pin) va kamera shu oraliqning o'rtasida
    topInset: Dp = 0.dp,
    bottomInset: Dp = 0.dp,
    onCenter: (LatLng) -> Unit = {},
    onMoveStarted: () -> Unit = {},
    // Markazdagi pin rangi ("Qayerga" tanlanayotganda — qizil); null — mavzu matni rangida
    pinColor: Color? = null,
) {
    val context = LocalContext.current
    val density = LocalDensity.current
    controller.top = with(density) { topInset.toPx() }.toDouble()
    controller.bottom = with(density) { bottomInset.toPx() }.toDouble()
    // Qorong'i xaritada qora belgi ko'rinmasdi: pin — mavzu matni rangida, haydovchi — firma rangida
    val pinTint = pinColor ?: MaterialTheme.colorScheme.onSurface
    val brand = MaterialTheme.colorScheme.primary.toArgb()
    val mapView = remember { MapView(context).apply { onCreate(Bundle()) } }
    val lifecycle = LocalLifecycleOwner.current.lifecycle
    val styleHolder = remember { arrayOfNulls<Style>(1) }
    // Mavzu almashsa uslub (kunduzgi/tungi) qayta yuklanadi; versiya o'zgargach belgilar qayta chiziladi
    val loadedStyle = remember { arrayOfNulls<String>(1) }
    var styleVersion by remember { mutableIntStateOf(0) }
    // Tinglovchilar xarita bir marta yaratilganda o'rnatiladi — eng oxirgi qiymatlar shu orqali
    val state = remember { arrayOf<Any?>(picking, onCenter, onMoveStarted) }
    state[0] = picking
    state[1] = onCenter
    state[2] = onMoveStarted

    DisposableEffect(lifecycle) {
        var started = false
        var resumed = false
        val observer = LifecycleEventObserver { _, event ->
            when (event) {
                Lifecycle.Event.ON_START -> mapView.onStart().also { started = true }
                Lifecycle.Event.ON_RESUME -> mapView.onResume().also { resumed = true }
                Lifecycle.Event.ON_PAUSE -> mapView.onPause().also { resumed = false }
                Lifecycle.Event.ON_STOP -> mapView.onStop().also { started = false }
                else -> Unit
            }
        }
        lifecycle.addObserver(observer)
        onDispose {
            lifecycle.removeObserver(observer)
            if (resumed) mapView.onPause()
            if (started) mapView.onStop()
            mapView.onDestroy()
            controller.map = null
        }
    }

    Box(modifier) {
        AndroidView(factory = {
            mapView.also { view ->
                view.getMapAsync { map ->
                    controller.map = map
                    // Ikki barmoq bilan aylantirish; burilganda kompas chiqadi (bosilsa — shimol tepada)
                    map.uiSettings.isRotateGesturesEnabled = true
                    map.uiSettings.isCompassEnabled = true
                    map.uiSettings.compassGravity = Gravity.TOP or Gravity.END
                    map.uiSettings.isAttributionEnabled = true
                    // Undan yaqinda xarita fayllari yo'q — bo'sh kulrang ekran ko'rinardi
                    map.setMaxZoomPreference(19.0)
                    map.cameraPosition = CameraPosition.Builder()
                        .target(initialCenter?.let { MapLatLng(it.lat, it.lng) } ?: UZBEKISTAN)
                        .zoom(if (initialCenter != null) 14.0 else 5.0)
                        .padding(0.0, controller.top, 0.0, controller.bottom).build()
                    map.addOnCameraMoveStartedListener { reason ->
                        @Suppress("UNCHECKED_CAST")
                        if (reason == MapLibreMap.OnCameraMoveStartedListener.REASON_API_GESTURE) (state[2] as () -> Unit)()
                    }
                    map.addOnCameraIdleListener {
                        @Suppress("UNCHECKED_CAST")
                        if (state[0] == true) {
                            val target = map.cameraPosition.target ?: return@addOnCameraIdleListener
                            (state[1] as (LatLng) -> Unit)(LatLng(target.latitude, target.longitude))
                        }
                    }
                    loadStyle(map, styleUrl, brand, density.density) { style ->
                        styleHolder[0] = style
                        loadedStyle[0] = styleUrl
                        styleVersion++
                    }
                }
            }
        }, modifier = Modifier.fillMaxSize())

        // Tanlash rejimi: pin ko'rinadigan maydon markazida (kamera padding'i bilan bir xil), uchi aynan markaz nuqtasida
        if (picking) {
            Box(Modifier.fillMaxSize().padding(top = topInset, bottom = bottomInset)) {
                Icon(
                    Icons.Default.Place,
                    contentDescription = null,
                    tint = pinTint,
                    modifier = Modifier.align(Alignment.Center).size(44.dp).offset(y = (-20).dp),
                )
            }
        }
    }

    // Panel balandligi o'zgardi — kamera padding'i yangilanadi (markaz o'sha nuqtada qoladi, endi ko'rinadigan joyda)
    LaunchedEffect(controller.top, controller.bottom) {
        val map = controller.map ?: return@LaunchedEffect
        map.moveCamera(CameraUpdateFactory.newCameraPosition(CameraPosition.Builder(map.cameraPosition).padding(0.0, controller.top, 0.0, controller.bottom).build()))
        val margin = (16 * mapView.resources.displayMetrics.density).toInt()
        map.uiSettings.setCompassMargins(0, controller.top.toInt() + margin, margin, 0)
    }

    LaunchedEffect(styleUrl) {
        val map = controller.map ?: return@LaunchedEffect
        if (loadedStyle[0] != null && loadedStyle[0] != styleUrl) {
            styleHolder[0] = null
            loadStyle(map, styleUrl, brand, density.density) { style ->
                styleHolder[0] = style
                loadedStyle[0] = styleUrl
                styleVersion++
            }
        }
    }

    // Mashina sakramaydi: yangi joy kelganda eski joyidan 1 soniyada suriladi va burchak eng qisqa tomonga buriladi.
    // Yangi nuqta animatsiya tugamasdan kelsa — effekt qayta boshlanadi va hozir chizilgan joydan davom etadi
    val shownDriver = remember { arrayOfNulls<LatLng>(1) }
    val shownHeading = remember { floatArrayOf(0f) }
    LaunchedEffect(picking, pickup, dropoff, driver, driverHeading, styleVersion) {
        val style = styleHolder[0] ?: return@LaunchedEffect
        val from = shownDriver[0]
        if (driver == null) {
            shownDriver[0] = null
            update(style, pickup, dropoff, null, 0f)
            return@LaunchedEffect
        }
        val target = driverHeading
            ?: from?.takeIf { CarMarker.distanceMeters(it, driver) > 8 }?.let { CarMarker.bearing(it, driver) }
            ?: shownHeading[0]
        // Birinchi marta yoki uzoqqa sakrash (GPS uzilib qolgan) — darhol joyiga
        if (from == null || CarMarker.distanceMeters(from, driver) > 1500) {
            shownDriver[0] = driver
            shownHeading[0] = target
            update(style, pickup, dropoff, driver, target)
            return@LaunchedEffect
        }
        val startHeading = shownHeading[0]
        val progress = Animatable(0f)
        progress.animateTo(1f, tween(1000, easing = LinearEasing)) {
            val f = value
            val at = LatLng(from.lat + (driver.lat - from.lat) * f, from.lng + (driver.lng - from.lng) * f)
            // Burilish tezroq (birinchi 40% da) — mashina yon bilan yurmasin
            val heading = CarMarker.lerpAngle(startHeading, target, (f / 0.4f).coerceAtMost(1f))
            shownDriver[0] = at
            shownHeading[0] = heading
            update(style, pickup, dropoff, at, heading)
        }
    }
}

private const val SOURCE = "client-points"

private const val CAR_IMAGE = "driver-car"

/** Uslub va belgilar qatlami (A — yashil, B — qizil, haydovchi — firma rangidagi mashina, yo'nalishi bo'yicha burilgan) */
private fun loadStyle(map: MapLibreMap, url: String, brand: Int, density: Float, onReady: (Style) -> Unit) {
    map.setStyle(Style.Builder().fromUri(url)) { style ->
        style.addSource(GeoJsonSource(SOURCE))
        style.addImage(CAR_IMAGE, CarMarker.bitmap(density, brand))
        style.addLayer(
            CircleLayer("points", SOURCE).withFilter(Expression.neq(Expression.get("kind"), Expression.literal("driver"))).withProperties(
                PropertyFactory.circleRadius(Expression.match(Expression.get("kind"), Expression.literal(9f), Expression.stop("driver", 11f))),
                PropertyFactory.circleColor(
                    Expression.match(
                        Expression.get("kind"), Expression.color(android.graphics.Color.parseColor("#1FA463")),
                        Expression.stop("dropoff", Expression.color(android.graphics.Color.parseColor("#D92D20"))),
                        Expression.stop("driver", Expression.color(brand)),
                    ),
                ),
                PropertyFactory.circleStrokeWidth(3f),
                PropertyFactory.circleStrokeColor(
                    Expression.match(Expression.get("kind"), Expression.color(android.graphics.Color.WHITE), Expression.stop("driver", Expression.color(android.graphics.Color.parseColor("#111111")))),
                ),
            ),
        )
        style.addLayer(
            SymbolLayer("driver", SOURCE).withFilter(Expression.eq(Expression.get("kind"), Expression.literal("driver"))).withProperties(
                PropertyFactory.iconImage(CAR_IMAGE),
                PropertyFactory.iconRotate(Expression.get("heading")),
                PropertyFactory.iconRotationAlignment(Property.ICON_ROTATION_ALIGNMENT_MAP),
                PropertyFactory.iconAllowOverlap(true),
                PropertyFactory.iconIgnorePlacement(true),
            ),
        )
        onReady(style)
    }
}

private fun update(style: Style, pickup: LatLng?, dropoff: LatLng?, driver: LatLng?, heading: Float) {
    val features = buildList {
        pickup?.let { add(feature(it, "pickup")) }
        dropoff?.let { add(feature(it, "dropoff")) }
        driver?.let { add(feature(it, "driver").apply { addNumberProperty("heading", heading) }) }
    }
    style.getSourceAs<GeoJsonSource>(SOURCE)?.setGeoJson(FeatureCollection.fromFeatures(features))
}

private fun feature(point: LatLng, kind: String) =
    Feature.fromGeometry(Point.fromLngLat(point.lng, point.lat)).apply { addStringProperty("kind", kind) }
